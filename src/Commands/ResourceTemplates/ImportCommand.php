<?php
namespace OSC\Commands\ResourceTemplates;

use Ahc\Cli\Exception\InvalidArgumentException;
use Exception;
use Omeka\DataType\Manager;
use OSC\Commands\AbstractCommand;
use OSC\Exceptions\WarningException;
use OSC\Helper\FileUtils;


class ImportCommand extends AbstractCommand
{
    /** @var \Common\Stdlib\EasyMeta $easyMeta */
    protected $easyMeta;

    public function __construct()
    {
        parent::__construct('resource-template:import', 'Import a resource template');
        $this->argument('<filename>', 'File to import from');
        $this->argument('[label]', 'Resource template label');
        $this->option('--update', 'Update existing resource template (if it exists)', 'boolval', false);
        $this->option('--ignore-deps', 'Ignore missing dependencies', 'boolval', false);
    }

    public function execute(string $filename, ?bool $update = false, ?string $label = null, ?bool $ignoreDeps = false): void
    {
        // Get Omeka instance and service manager
        $omekaInstance = $this->getOmekaInstance();
        $serviceManager = $omekaInstance->getServiceManager();

        $api = $omekaInstance->getApi();

        try {
            $this->easyMeta = $serviceManager->get('Common\EasyMeta');
        } catch (Exception $e) {
            throw new Exception("This features requires the 'Common' module to be installed.");
        }

        // check if file exists and is readable
        if (!is_readable($filename)) {
            throw new Exception("File not found or not readable: {$filename}");
        }

        // read file content and check if valid json
        $content = file_get_contents($filename);
        $resourceTemplateData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in file: {$filename}");
        }

        // Get or check resource template label
        $label = $label ?? $resourceTemplateData['o:label'] ?? null;
        if (!$label) {
            throw new Exception("The resource template label is missing. You can specify it as second argument.");
        }
        $resourceTemplateData['o:label'] = $label;

        // Check if the resource template already exists.
        try {
            $resourceTemplate = $api->read('resource_templates', ['label' => $label])->getContent();
        } catch (Exception $e) {
            $resourceTemplate = null;
        }
        if (!$update && $resourceTemplate)  {
            throw new WarningException("The resource template named '{$label}' is already available and is skipped.");
        }

        // Check for missing dependencies.
        $missing = $this->checkMissingDependencies($resourceTemplateData);
        if (!empty($missing) && !$ignoreDeps) {
            $messages = [];
            foreach ($missing as $type => $items) {
                $messages[] = sprintf("- %s: %s", ucfirst(str_replace('_', ' ', $type)), implode(', ', $items));
            }
            $this->warn("The resource template '{$label}' has missing dependencies:", true);
            $this->warn(implode("\n", $messages), true);

            throw new Exception("Could not import the resource template '{$label}' due to missing dependencies.");
        }

        // Flag members and data types as valid.
        $resourceTemplateData = $this->flagValid($resourceTemplateData);

        if ($resourceTemplate && $update) {
            $response = $api->update('resource_templates', $resourceTemplate->id(), $resourceTemplateData)->getContent();
            if (!$response) {
                throw new Exception("An error occurred while updating the resource template '{$label}'.");
            }
            $this->ok("Succesfully updated Resource template '{$label}'.", true);
        } else {
            $response = $api->create('resource_templates', $resourceTemplateData)->getContent();
            if (!$response) {
                throw new Exception("An error occurred while creating the resource template '{$label}'.");
            }
            $this->ok("Succesfully created Resource template '{$label}'.", true);
        }
    }

    /**
     *
     */

    /**
     * Flag members and data types as valid.
     *
     * Copy of the method of the Common module
     * Copyright Daniel Berthereau, 2020-2025
     *
     * @see \Common\ManageModuleAndResources::flagValid()
     */
    protected function flagValid(iterable $import)
    {
        $getDataTypesByName = function ($dataTypesNameLabels) {
            $result = [];
            foreach ($dataTypesNameLabels ?? [] as $index => $dataTypeNameLabel) {
                // Manage associative array name / label, even if it should not exist.
                if (is_string($dataTypeNameLabel)) {
                    if (is_numeric($index)) {
                        $result[$dataTypeNameLabel] = is_numeric($index)
                            ? ['name' => $dataTypeNameLabel, 'label' => $dataTypeNameLabel]
                            : ['name' => $index, 'label' => $dataTypeNameLabel];
                    } else {
                        // TODO Finalize here?
                    }
                } else {
                    $result[$dataTypeNameLabel['name']] = $dataTypeNameLabel;
                }
            }
            return $result;
        };

        if (isset($import['o:resource_class'])) {
            $vocabPrefix = $this->easyMeta->vocabularyPrefix($import['o:resource_class']['vocabulary_namespace_uri']);
            if ($vocabPrefix) {
                $import['o:resource_class']['vocabulary_prefix'] = $vocabPrefix;
                $import['o:resource_class']['o:id'] = $this->easyMeta->resourceClassId($vocabPrefix . ':' . $import['o:resource_class']['local_name']);
            }
        }

        foreach (['o:title_property', 'o:description_property'] as $property) {
            if (isset($import[$property])) {
                $vocabPrefix = $this->easyMeta->vocabularyPrefix($import[$property]['vocabulary_namespace_uri']);
                if ($vocabPrefix) {
                    $import[$property]['vocabulary_prefix'] = $vocabPrefix;
                    $import[$property]['o:id'] = $this->easyMeta->propertyId($vocabPrefix . ':' . $import[$property]['local_name']);
                }
            }
        }

        foreach ($import['o:resource_template_property'] as $key => $property) {
            $vocabPrefix = $this->easyMeta->vocabularyPrefix($property['vocabulary_namespace_uri']);
            if ($vocabPrefix) {
                $import['o:resource_template_property'][$key]['vocabulary_prefix'] = $vocabPrefix;
                $propertyId = $this->easyMeta->propertyId($vocabPrefix . ':' . $property['local_name']);
                if ($propertyId) {
                    $import['o:resource_template_property'][$key]['o:property'] = ['o:id' => $propertyId];
                    // Check the deprecated "data_type_name" if needed and
                    // normalize it.
                    if (!array_key_exists('data_types', $import['o:resource_template_property'][$key])) {
                        if (!empty($import['o:resource_template_property'][$key]['data_type_name'])
                            && !empty($import['o:resource_template_property'][$key]['data_type_label'])
                        ) {
                            $import['o:resource_template_property'][$key]['data_types'] = [[
                                'name' => $import['o:resource_template_property'][$key]['data_type_name'],
                                'label' => $import['o:resource_template_property'][$key]['data_type_label'],
                            ]];
                        } else {
                            $import['o:resource_template_property'][$key]['data_types'] = [];
                        }
                    }
                    unset($import['o:resource_template_property'][$key]['data_type_name']);
                    unset($import['o:resource_template_property'][$key]['data_type_label']);
                    $import['o:resource_template_property'][$key]['data_types'] = $getDataTypesByName($import['o:resource_template_property'][$key]['data_types']);
                    // Prepare the list of standard data types.
                    $import['o:resource_template_property'][$key]['o:data_type'] = [];
                    foreach (array_keys($import['o:resource_template_property'][$key]['data_types']) as $name) {
                        $known = $this->easyMeta->dataTypeName($name);
                        if ($known) {
                            $import['o:resource_template_property'][$key]['o:data_type'][] = $known;
                            $import['o:resource_template_property'][$key]['data_types'][$name]['name'] = $known;
                        }
                    }
                    $import['o:resource_template_property'][$key]['o:data_type'] = array_unique($import['o:resource_template_property'][$key]['o:data_type']);
                    // Prepare the list of standard data types for duplicated
                    // properties (only one most of the time, that is the main).
                    $import['o:resource_template_property'][$key]['o:data'] = array_values($import['o:resource_template_property'][$key]['o:data'] ?? []);
                    $import['o:resource_template_property'][$key]['o:data'][0]['data_types'] = $import['o:resource_template_property'][$key]['data_types'] ?? [];
                    $import['o:resource_template_property'][$key]['o:data'][0]['o:data_type'] = $import['o:resource_template_property'][$key]['o:data_type'] ?? [];
                    $first = true;
                    foreach ($import['o:resource_template_property'][$key]['o:data'] as $k => $rtpData) {
                        if ($first) {
                            $first = false;
                            // Specific to the installer.
                            unset($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
                            continue;
                        }
                        // Prepare the list of standard data types if any.
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = [];
                        if (empty($rtpData['data_types'])) {
                            continue;
                        }
                        $import['o:resource_template_property'][$key]['o:data'][$k]['data_types'] = $getDataTypesByName($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
                        foreach (array_keys($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']) as $name) {
                            $known = $this->easyMeta->dataTypeName($name);
                            if ($known) {
                                $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'][] = $known;
                                $import['o:resource_template_property'][$key]['o:data'][$k]['data_types'][$name]['name'] = $known;
                            }
                        }
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = array_unique($import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type']);
                        // Specific to the installer.
                        unset($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
                    }
                }
            }
        }

        return $import;
    }

    /**
     * Check if a resource template has missing dependencies.
     *
     * @param array $resourceTemplateData The resource template data from file
     * @return array Array of missing dependencies grouped by type
     * @throws Exception If critical dependencies are missing
     */
    protected function checkMissingDependencies(array $resourceTemplateData): array
    {
        $missing = [
            'vocabularies' => [],
            'properties' => [],
            'resource_classes' => [],
            'data_types' => [],
        ];

        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        $dataTypeManager = $serviceManager->get('Omeka\DataTypeManager');
        $registeredDataTypes = $dataTypeManager->getRegisteredNames();

        // Check resource class if present
        if (isset($resourceTemplateData['o:resource_class'])) {
            $vocabUri = $resourceTemplateData['o:resource_class']['vocabulary_namespace_uri'];
            $vocabPrefix = $this->easyMeta->vocabularyPrefix($vocabUri);
            if (!$vocabPrefix) {
                $missing['vocabularies'][] = $vocabUri;
            } else {
                $localName = $resourceTemplateData['o:resource_class']['local_name'];
                $classId = $this->easyMeta->resourceClassId($vocabPrefix . ':' . $localName);
                if (!$classId) {
                    $missing['resource_classes'][] = $vocabPrefix . ':' . $localName;
                }
            }
        }

        // Check title and description properties
        foreach (['o:title_property', 'o:description_property'] as $propertyKey) {
            if (isset($resourceTemplateData[$propertyKey])) {
                $vocabUri = $resourceTemplateData[$propertyKey]['vocabulary_namespace_uri'];
                $vocabPrefix = $this->easyMeta->vocabularyPrefix($vocabUri);
                if (!$vocabPrefix) {
                    if (!in_array($vocabUri, $missing['vocabularies'])) {
                        $missing['vocabularies'][] = $vocabUri;
                    }
                } else {
                    $localName = $resourceTemplateData[$propertyKey]['local_name'];
                    $propertyId = $this->easyMeta->propertyId($vocabPrefix . ':' . $localName);
                    if (!$propertyId) {
                        $missing['properties'][] = $vocabPrefix . ':' . $localName;
                    }
                }
            }
        }

        // Check template properties
        foreach ($resourceTemplateData['o:resource_template_property'] ?? [] as $property) {
            $vocabUri = $property['vocabulary_namespace_uri'];
            $vocabPrefix = $this->easyMeta->vocabularyPrefix($vocabUri);

            if (!$vocabPrefix) {
                if (!in_array($vocabUri, $missing['vocabularies'])) {
                    $missing['vocabularies'][] = $vocabUri;
                }
            } else {
                $localName = $property['local_name'];
                $propertyId = $this->easyMeta->propertyId($vocabPrefix . ':' . $localName);
                if (!$propertyId) {
                    $propertyTerm = $vocabPrefix . ':' . $localName;
                    if (!in_array($propertyTerm, $missing['properties'])) {
                        $missing['properties'][] = $propertyTerm;
                    }
                }
            }

            // Check data types
            $dataTypes = $property['data_types'] ?? [];
            if (!empty($property['data_type_name'])) {
                $dataTypes[] = ['name' => $property['data_type_name']];
            }

            foreach ($dataTypes as $dataType) {
                $name = is_array($dataType) ? $dataType['name'] : $dataType;
                if (!in_array($name, $registeredDataTypes)) {
                    if (!in_array($name, $missing['data_types'])) {
                        $missing['data_types'][] = $name;
                    }
                }
            }

            // Check nested data types in o:data
            foreach ($property['o:data'] ?? [] as $rtpData) {
                foreach ($rtpData['data_types'] ?? [] as $dataType) {
                    $name = is_array($dataType) ? $dataType['name'] : $dataType;
                    if (!in_array($name, $registeredDataTypes)) {
                        if (!in_array($name, $missing['data_types'])) {
                            $missing['data_types'][] = $name;
                        }
                    }
                }
            }
        }

        // Filter out empty arrays
        $missing = array_filter($missing);

        return $missing;
    }

}