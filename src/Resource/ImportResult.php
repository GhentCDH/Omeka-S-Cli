<?php
namespace OSC\Resource;

/** The outcome of importing one resource. */
final class ImportResult
{
    public const CREATED = 'created';
    public const EXISTS = 'exists';
    public const AMBIGUOUS = 'ambiguous';
    public const WOULD_CREATE = 'would create';

    /** @param string[] $warnings */
    private function __construct(
        private string $status,
        private ?int $id,
        private int $count,
        private ?string $title,
        private array $warnings,
    ) {
    }

    /** @param string[] $warnings */
    public static function created(int $id, ?string $title, array $warnings): self
    {
        return new self(self::CREATED, $id, 1, $title, $warnings);
    }

    public static function exists(int $id, ?string $title): self
    {
        return new self(self::EXISTS, $id, 1, $title, []);
    }

    public static function ambiguous(int $count, ?string $title): self
    {
        return new self(self::AMBIGUOUS, null, $count, $title, []);
    }

    /** @param string[] $warnings */
    public static function wouldCreate(?string $title, array $warnings): self
    {
        return new self(self::WOULD_CREATE, null, 0, $title, $warnings);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /** How many existing resources matched (for an ambiguous result). */
    public function count(): int
    {
        return $this->count;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'id' => $this->id, 'title' => $this->title, 'warnings' => $this->warnings];
    }
}
