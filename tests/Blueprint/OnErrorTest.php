<?php
namespace Tests\Blueprint;

use InvalidArgumentException;
use OSC\Blueprint\OnError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OnError::class)]
class OnErrorTest extends TestCase
{
    public function testAnEntryWithoutOnErrorFails(): void
    {
        [$onError, $entry] = OnError::split(['title' => 'Harbour']);

        $this->assertSame(OnError::Fail, $onError);
        $this->assertSame(['title' => 'Harbour'], $entry);
    }

    public function testSplitReturnsThePolicyAndTheEntryWithoutIt(): void
    {
        [$onError, $entry] = OnError::split(['source' => './harbour.jsonld', 'onError' => 'skip', 'title' => 'Harbour']);

        $this->assertSame(OnError::Skip, $onError);
        $this->assertSame(['source' => './harbour.jsonld', 'title' => 'Harbour'], $entry);
    }

    public function testFailCanBeExplicit(): void
    {
        $this->assertSame(OnError::Fail, OnError::split(['onError' => 'fail'])[0]);
    }

    public function testAnUnknownValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("onError must be 'fail' or 'skip', not 'maybe'.");

        OnError::split(['onError' => 'maybe']);
    }

    public function testANonStringValueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OnError::split(['onError' => true]);
    }
}
