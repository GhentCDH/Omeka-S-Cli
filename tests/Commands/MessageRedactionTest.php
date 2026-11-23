<?php
namespace Tests\Commands;

use Ahc\Cli\IO\Interactor;
use OSC\Commands\AbstractCommand;
use OSC\Helper\ResourceFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/** Messages never show the credentials of a URL, whichever command or helper builds them. */
#[CoversClass(AbstractCommand::class)]
#[UsesClass(ResourceFetcher::class)]
class MessageRedactionTest extends TestCase
{
    public function testMessagesHideCredentials(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'osc-out-');
        $command = new class ('test:redaction', $file) extends AbstractCommand {
            private Interactor $interactor;
            public function __construct(string $name, string $file)
            {
                parent::__construct($name);
                $this->interactor = new Interactor(null, $file);
            }
            protected function io(): Interactor
            {
                return $this->interactor;
            }
            public function execute(): void
            {
            }
        };
        try {
            $url = 'https://user:secret@example.org/a.zip';
            $command->note("note {$url}", true);
            $command->info("info {$url}", true);
            $command->ok("ok {$url}", true);
            $command->warn("warn {$url}", true);
            $command->error("error {$url}", true);
            $output = file_get_contents($file);
            $this->assertStringNotContainsString('secret', $output);
            $this->assertSame(5, substr_count($output, 'https://***@example.org/a.zip'));
        } finally {
            unlink($file);
        }
    }
}
