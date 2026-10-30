<?php

namespace Tests\Feature;

use App\Workflows\CustomerVerification\SanctionsRegistry;
use Tests\TestCase;

class SanctionsRegistryTest extends TestCase
{
    public function test_matches_sanctioned_phone_numbers_whatever_the_formatting(): void
    {
        $this->assertSame(
            ['Phone number +1 (555) 010-0666 is on the Demo sanctions list'],
            (new SanctionsRegistry)->screenContact('+1 (555) 010-0666', null),
        );
    }

    public function test_matches_sanctioned_emails_ignoring_case(): void
    {
        $this->assertSame(
            ['Email address  Blocked@Example.com is on the Demo sanctions list'],
            (new SanctionsRegistry)->screenContact(null, ' Blocked@Example.com'),
        );
    }

    public function test_reports_both_contact_matches(): void
    {
        $this->assertCount(2, (new SanctionsRegistry)->screenContact('+15550100666', 'blocked@example.com'));
    }

    public function test_clears_unlisted_contact_details(): void
    {
        $this->assertSame([], (new SanctionsRegistry)->screenContact('+1 555 010 0667', 'ada@example.com'));
    }

    public function test_matches_sanctioned_addresses_despite_abbreviations_case_and_punctuation(): void
    {
        $sanctions = new SanctionsRegistry;

        $this->assertSame(
            ['Address 666 shadow ln., 66666, united states is on the Demo sanctions list'],
            $sanctions->screenAddress('666 shadow ln.', '66666', 'united states'),
        );
        $this->assertCount(1, $sanctions->screenAddress('13 Blackwater Rd', 'ec1a1bb', 'United Kingdom'));
    }

    public function test_clears_an_address_that_only_partly_matches(): void
    {
        $sanctions = new SanctionsRegistry;

        $this->assertSame([], $sanctions->screenAddress('666 Shadow Lane', '66667', 'United States'));
        $this->assertSame([], $sanctions->screenAddress('667 Shadow Lane', '66666', 'United States'));
    }
}
