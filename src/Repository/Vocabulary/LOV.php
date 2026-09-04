<?php
namespace OSC\Repository\Vocabulary;

use OSC\Helper\ResourceFetcher;
use OSC\Repository\AbstractRepository;

/**
 * Linked Open Vocabularies (LOV) Repository
 *
 * Fetches vocabulary metadata from https://lov.linkeddata.es/
 *
 * @template T of VocabularyItem
 * @template-extends AbstractRepository<VocabularyItem>
 */
class LOV extends AbstractRepository
{
    public function getId(): string
    {
        return 'lov';
    }

    public function getDisplayName(): string
    {
        return 'Linked Open Vocabularies (LOV)';
    }

    /**
     * Query the LOV SPARQL endpoint and return results as CSV
     *
     * @return string CSV data from the SPARQL query
     * @throws \Exception If the query fails
     */
    protected function querySparqlEndpoint(): string
    {
        $SPARQL_ENDPOINT = 'https://lov.linkeddata.es/dataset/sparql';
        $SPARQL_QUERY = <<<'SPARQL'
            PREFIX vann:<http://purl.org/vocab/vann/>
            PREFIX voaf:<http://purl.org/vocommons/voaf#>
            PREFIX dcat:<http://www.w3.org/ns/dcat#>
            PREFIX dcterms:<http://purl.org/dc/terms/>
            PREFIX rdfs:<http://www.w3.org/2000/01/rdf-schema#>
             
            SELECT ?vocabURI ?label ?vocabPrefix ?namespace ?distributionURI ?issued (IF(BOUND(?desc), REPLACE(?desc, "\r?\n|\r", "\u000B"), "") AS ?description) 
            WHERE {
                # LOV moved the catalogue metadata out of the former named graph into the
                # default graph, so no GRAPH scoping here.
                    ?vocabURI a voaf:Vocabulary.
                    ?vocabURI vann:preferredNamespacePrefix ?vocabPrefix.
                    
                    # Get label (title)
                    OPTIONAL { ?vocabURI dcterms:title ?label filter (lang(?label) = "en") }
                    
                    # Get namespace URI
                    OPTIONAL { ?vocabURI vann:preferredNamespaceUri ?namespace }
                    
                    # Get distribution - only those identified by URI (not blank nodes)
                    OPTIONAL { 
                        ?vocabURI dcat:distribution ?distributionURI.
                        FILTER(isURI(?distributionURI))
                  
                        # Get the issued date
                        OPTIONAL { ?distributionURI dcterms:issued ?issued }
                        
                        # Only keep this distribution if no other URI distribution has a later date
                        FILTER NOT EXISTS {
                            ?vocabURI dcat:distribution ?otherDist.
                            FILTER(isURI(?otherDist))
                            ?otherDist dcterms:issued ?otherIssued.
                            ?distributionURI dcterms:issued ?thisIssued.
                            FILTER(?otherIssued > ?thisIssued)
                        }
                    }
                
                    # Get description
                    OPTIONAL { ?vocabURI dcterms:description ?desc filter (lang(?desc) = "en") }
            }
            ORDER BY ?vocabPrefix
        SPARQL;

        // The endpoint negotiates the response format on the Accept header; the SPARQL 'format'
        // query parameter is ignored, so CSV is requested via the header only.
        $url = $SPARQL_ENDPOINT . '?' . http_build_query(['query' => $SPARQL_QUERY]);

        return ResourceFetcher::fetch($url, ['Accept: text/csv']);
    }

    /**
     * @return VocabularyItem[]
     */
    public function entries(): array
    {
        $vocabularies = [];

        $csv = $this->querySparqlEndpoint();
        $csv = array_map('str_getcsv', explode(PHP_EOL, $csv));

        // validate csv structure
        if (!is_array($csv)) {
            throw new \UnexpectedValueException("Invalid SPARQL query result.");
        }
        if (empty($csv)) {
            return $vocabularies;
        }

        $header = array_shift($csv);
        $expectedKeys = ['vocabURI', 'label', 'vocabPrefix', 'namespace', 'distributionURI', 'issued'];
        if (count($expectedKeys) !== count(array_intersect($header, $expectedKeys)) ) {
            throw new \UnexpectedValueException("Invalid SPARQL query result.");
        }

        // Convert csv to associative array
        $data = [];
        foreach ($csv as $row) {
            if (count($row) === count($header)) {
                $data[] = array_combine($header, $row);
            }
        }

        if (empty($data)) {
            return $vocabularies;
        }

        // Convert LOV data to VocabularyItem objects
        foreach ($data as $item) {
            // Skip items without required fields
            if (!isset($item['vocabPrefix']) || !isset($item['namespace'])) {
                continue;
            }

            $prefix = $item['vocabPrefix'];
            $vocabularyId = $this->getId().":".strtolower($prefix);

            $label = $item['label'] ?? $prefix;
            $comment = isset($item['description']) ? str_replace("\v", "\n", $item['description']) : null;
            $url = $item['distributionURI'];

            $vocabularies[$vocabularyId] = new VocabularyItem(
                id: $vocabularyId,
                label: $label,
                url: $url,
                namespaceUri: $item['namespace'],
                prefix: $prefix,
                format: 'turtle', // LOV doesn't specify format in the list endpoint
                comment: $comment,
            );
        }

        return $vocabularies;
    }
}

