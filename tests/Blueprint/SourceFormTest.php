<?php
namespace Tests\Blueprint;

use OSC\Blueprint\SourceForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceForm::class)]
class SourceFormTest extends TestCase
{
    /**
     * The table of values from the shared spec (omeka-s-blueprints tests/datasource-forms.test.mjs):
     * each value has exactly one form, or none.
     *
     * @return array<string,array{0:string,1:?SourceForm}>
     */
    public static function specCases(): array
    {
        $cases = [
            'https://example.org/M.zip' => SourceForm::Url, 'HTTPS://example.org/M.zip' => SourceForm::Url,
            'http://example.org/a.php' => SourceForm::Url, 'https://github.com/owner/repo' => SourceForm::Url,
            'https://gitlab.com/o/r.git#v1' => SourceForm::Url,
            'https://github.com/o/r/releases/download/v1/M.zip' => SourceForm::Url,
            'https://' => null, 'http:///x.zip' => null,
            'gh:owner/repo' => SourceForm::Git, 'gh:owner/repo#v1' => SourceForm::Git,
            'gh:owner/repo@v1:dist/M.zip' => SourceForm::Git, 'gl:group/sub/repo@v1:config/a.php' => SourceForm::Git,
            'git@github.com:owner/repo.git#main' => SourceForm::Git, 'gh:owner' => SourceForm::Git,
            './config/a.php' => SourceForm::RelativePath, 'config/a.php' => SourceForm::RelativePath,
            '../shared/a.php' => SourceForm::RelativePath, 'a/../b.php' => SourceForm::RelativePath,
            '.\\zips\\M.zip' => SourceForm::RelativePath, './My%20File.pdf' => SourceForm::RelativePath,
            "caf\u{00e9}.jpg" => SourceForm::RelativePath, './10:00.json' => SourceForm::RelativePath,
            '10:00.json' => SourceForm::RelativePath, 'a:b.json' => null, 'git@foo/bar' => SourceForm::RelativePath,
            'Mapping' => SourceForm::RelativePath,
            '/etc/a.php' => null, '\\\\server\\share\\a.php' => null, 'C:\\a.php' => null, 'C:/a.php' => null,
            'file:///tmp/a.php' => null, 'php://input' => null, 'ftp://example.org/a.zip' => null,
            'mailto:a@example.org' => null,
            'deploy@gitlab.example.org:o/r.git' => SourceForm::RelativePath, 'CleanUrl:3.4' => null,
            'git@:x' => SourceForm::Git, '' => null,
            ' https://example.org/M.zip' => null, ' ./a.zip' => null, "\ta.zip" => null, "\u{00a0}a.zip" => null,
            './a.zip ' => SourceForm::RelativePath,
        ];
        $data = [];
        foreach ($cases as $value => $form) {
            $data[json_encode((string) $value, JSON_UNESCAPED_UNICODE)] = [(string) $value, $form];
        }
        return $data;
    }

    #[DataProvider('specCases')]
    public function testFormOfASpecValue(string $value, ?SourceForm $expected): void
    {
        $this->assertSame($expected, SourceForm::of($value));
    }

    #[DataProvider('specCases')]
    public function testOnlyAValueWithoutAFormHasARejectionReason(string $value, ?SourceForm $expected): void
    {
        $reason = SourceForm::rejection($value);
        $expected === null ? $this->assertNotNull($reason) : $this->assertNull($reason);
    }

    public function testAFinalNewlineIsNotIgnored(): void
    {
        // PCRE's $ also matches before a final "\n"; the forms are prefix rules, so the newline stays in the value
        $this->assertSame(SourceForm::RelativePath, SourceForm::of("./a.php\n"));
        $this->assertNull(SourceForm::of("\n./a.php"));
    }

    public function testRejectionReasonsNameTheProblem(): void
    {
        $this->assertStringContainsString('empty', SourceForm::rejection(''));
        $this->assertStringContainsString('whitespace', SourceForm::rejection(' ./a.zip'));
        $this->assertStringContainsString('absolute path', SourceForm::rejection('/etc/a.php'));
        $this->assertStringContainsString('absolute path', SourceForm::rejection('C:\\a.php'));
        $this->assertStringContainsString('host', SourceForm::rejection('http:///x.zip'));
        $this->assertStringContainsString("'./'", SourceForm::rejection('CleanUrl:3.4'));
        $this->assertStringContainsString('scheme', SourceForm::rejection('php://input'));
        $this->assertStringContainsString('scheme', SourceForm::rejection('a:b.json'));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function repositoryCases(): array
    {
        $cases = [
            'gh:o/r' => true, 'gh:o/r#v1' => true, 'git@gitlab.com:g/h.git' => true, 'git@gitlab.com:g/h.git#main' => true,
            'https://github.com/o/r' => true, 'https://github.com/o/r/' => true, 'https://github.com/o/r#v2' => true,
            'HTTPS://github.com/o/r' => true, 'https://gitlab.com/g/sub/r.git#v2' => true, 'http://example.org/r.git' => true,
            'https://example.org/r.git?x=1' => true,
            'gh:o/r@v1:dist/M.zip' => false, 'gh:o/r:dist/M.zip' => false, 'gl:g/r@v1:f.zip' => false,
            'https://github.com/o/r/blob/main/a.zip' => false, 'https://github.com/o/r/archive/refs/tags/v1.zip' => false,
            'https://github.com/o/r/releases/download/v1/M.zip' => false, 'https://example.org/a.zip' => false,
            'https://gitlab.com/group/repo' => false, 'https://github.com/o' => false, 'https://www.github.com/o/r' => false,
            './a.zip' => false, 'zips/r.git' => false, '' => false,
        ];
        $data = [];
        foreach ($cases as $value => $expected) {
            $data[(string) $value === '' ? '(empty)' : (string) $value] = [(string) $value, $expected];
        }
        return $data;
    }

    #[DataProvider('repositoryCases')]
    public function testWhetherASourceIsARepository(string $value, bool $expected): void
    {
        $this->assertSame($expected, SourceForm::isRepository($value));
    }
}
