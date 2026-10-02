<?php

declare(strict_types=1);

namespace ChatLens\Tests\Analysis;

use ChatLens\Analysis\QuoteVerifier;
use PHPUnit\Framework\TestCase;

final class QuoteVerifierTest extends TestCase
{
    private QuoteVerifier $v;

    protected function setUp(): void
    {
        $this->v = new QuoteVerifier();
    }

    public function testExactQuote(): void
    {
        self::assertTrue($this->v->verify('please ban these sellers', ['Admins, please ban these sellers, this is a chat']));
    }

    public function testIgnoresCaseWhitespaceAndQuotes(): void
    {
        self::assertTrue($this->v->verify('«Please   BAN these sellers!»', ['admins, please ban these sellers']));
    }

    public function testShortenedWithEllipsisMustKeepOrder(): void
    {
        $messages = ['Selling a bike, almost new, 4500. Photos in DM'];

        self::assertTrue($this->v->verify('Selling a bike… Photos in DM', $messages));
        self::assertTrue($this->v->verify('Selling a bike ... 4500', $messages));
        self::assertFalse($this->v->verify('Photos in DM … Selling a bike', $messages));
    }

    public function testFragmentsMustBeInOneMessage(): void
    {
        self::assertFalse($this->v->verify('hello … world', ['hello there', 'big world']));
    }

    public function testParaphraseIsRejected(): void
    {
        self::assertFalse($this->v->verify('ads should be banned', ['Admins, please ban these sellers']));
    }

    public function testCyrillicAndYo(): void
    {
        self::assertTrue($this->v->verify('Всё ещё продаётся?', ['Велосипед все еще продается? Какая рама']));
    }

    public function testEmptyQuote(): void
    {
        self::assertFalse($this->v->verify('…', ['anything']));
    }
}
