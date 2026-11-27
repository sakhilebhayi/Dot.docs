<?php

namespace Tests\Unit;

use App\Support\MailText;
use PHPUnit\Framework\TestCase;

/** R2-11: bare domains in names and titles must not arrive in mail as something a mail client links. */
class MailTextTest extends TestCase
{
    public function test_a_bare_domain_is_broken_up_invisibly(): void
    {
        $this->assertSame("visit example.\u{200B}com today", MailText::defang('visit example.com today'));
        $this->assertSame("ada@mail.\u{200B}example.\u{200B}org", MailText::defang('ada@mail.example.org'));
        $this->assertSame("www.\u{200B}example.\u{200B}co.\u{200B}za", MailText::defang('www.example.co.za'));
    }

    public function test_numbers_and_sentence_ends_are_left_alone(): void
    {
        $this->assertSame('Version 3.5 is out. Next week.', MailText::defang('Version 3.5 is out. Next week.'));
        $this->assertSame('...', MailText::defang('...'));
    }

    public function test_plain_carries_the_break_as_an_entity(): void
    {
        $this->assertSame('example&#46;&#8203;com', MailText::plain('example.com'));
    }

    public function test_text_is_single_line_bounded_and_defanged_without_escaping(): void
    {
        $this->assertSame("A & B at evil.\u{200B}test", MailText::text("A & B\nat evil.test", 80));
        $this->assertSame('Ada', MailText::text('Ada'));
    }
}
