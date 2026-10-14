<?php
namespace Tests\Blueprint\Bundle;

use Exception;
use OSC\Blueprint\Bundle\BundleOutput;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BundleOutput::class)]
#[UsesClass(Path::class)]
class BundleOutputTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bp_output_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    /** @return string[] Leftover temporary directories next to the target */
    private function partials(): array
    {
        return glob("{$this->dir}/bundle.partial-*") ?: [];
    }

    public function testCommitMovesTheBundleIntoAnAbsentTarget(): void
    {
        $output = BundleOutput::prepare("{$this->dir}/bundle", false);
        file_put_contents($output->path() . '/blueprint.json', '{}');
        $output->commit();

        $this->assertFileExists("{$this->dir}/bundle/blueprint.json");
        $this->assertSame([], $this->partials());
    }

    public function testANonEmptyTargetIsRefusedWithoutForceAndLeftAlone(): void
    {
        mkdir("{$this->dir}/bundle");
        file_put_contents("{$this->dir}/bundle/old.txt", 'old');

        try {
            BundleOutput::prepare("{$this->dir}/bundle", false);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('use --force', $e->getMessage());
        }
        $this->assertFileExists("{$this->dir}/bundle/old.txt");
        $this->assertSame([], $this->partials());
    }

    public function testForceReplacesTheTargetOnlyOnCommit(): void
    {
        mkdir("{$this->dir}/bundle");
        file_put_contents("{$this->dir}/bundle/old.txt", 'old');

        $output = BundleOutput::prepare("{$this->dir}/bundle", true);
        $this->assertFileExists("{$this->dir}/bundle/old.txt"); // untouched while the bundle is built
        file_put_contents($output->path() . '/blueprint.json', '{}');
        $output->commit();

        $this->assertFileDoesNotExist("{$this->dir}/bundle/old.txt");
        $this->assertFileExists("{$this->dir}/bundle/blueprint.json");
    }

    public function testDiscardRemovesThePartialBundleAndKeepsTheTarget(): void
    {
        mkdir("{$this->dir}/bundle");
        file_put_contents("{$this->dir}/bundle/old.txt", 'old');

        $output = BundleOutput::prepare("{$this->dir}/bundle", true);
        file_put_contents($output->path() . '/half.zip', 'x');
        $output->discard();

        $this->assertFileExists("{$this->dir}/bundle/old.txt");
        $this->assertSame([], $this->partials());
    }

    public function testAFileTargetIsRefused(): void
    {
        file_put_contents("{$this->dir}/bundle", 'a file');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('is not a directory');
        BundleOutput::prepare("{$this->dir}/bundle", true);
    }
}
