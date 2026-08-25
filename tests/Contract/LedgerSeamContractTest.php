<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Platform\FinancialLedger\Contracts\Journal;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Contract\Support\LedgerSeamStub;
use Tests\TestCase;

final class LedgerSeamContractTest extends TestCase
{
    public function test_the_contract_uses_l4s_real_post_and_post_reversal_signatures(): void
    {
        $post = new ReflectionMethod(Journal::class, 'post');
        $postParameters = $post->getParameters();

        $this->assertSame(
            ['businessKey', 'entityRef', 'sourceType', 'sourceId', 'entries', 'correlationId', 'occurredAt'],
            array_map(static fn ($parameter): string => $parameter->getName(), $postParameters),
        );
        $entityRefTypes = array_map(
            static fn ($type): string => $type->getName(),
            $postParameters[1]->getType()->getTypes(),
        );
        sort($entityRefTypes);
        $this->assertSame(['int', 'string'], $entityRefTypes);
        $this->assertTrue($postParameters[5]->isDefaultValueAvailable());
        $this->assertTrue($postParameters[6]->isDefaultValueAvailable());

        $reversal = new ReflectionMethod(Journal::class, 'postReversal');

        $this->assertSame(
            ['originalBusinessKey', 'reason', 'kind', 'correlationId', 'occurredAt'],
            array_map(static fn ($parameter): string => $parameter->getName(), $reversal->getParameters()),
        );
        $this->assertTrue($reversal->getParameters()[2]->isDefaultValueAvailable());
        $this->assertTrue($reversal->getParameters()[3]->isDefaultValueAvailable());
        $this->assertTrue($reversal->getParameters()[4]->isDefaultValueAvailable());
    }

    public function test_the_contract_rejects_an_unbalanced_entry_set(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LedgerSeamStub)->post(
            businessKey: 'payment:unbalanced',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'event-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 999],
            ],
        );
    }

    public function test_the_contract_rejects_a_duplicate_business_key(): void
    {
        $journal = new LedgerSeamStub;
        $entries = $this->balancedEntries();

        $journal->post('payment:duplicate', 'badan-usaha-1', 'payment', 'event-1', $entries);

        $this->expectException(InvalidArgumentException::class);
        $journal->post('payment:duplicate', 'badan-usaha-1', 'payment', 'event-1', $entries);
    }

    public function test_post_does_not_open_a_transaction_of_its_own(): void
    {
        $begun = 0;
        Event::listen(TransactionBeginning::class, static function () use (&$begun): void {
            $begun++;
        });

        $levelBefore = DB::transactionLevel();
        (new LedgerSeamStub)->post('payment:no-nested-transaction', 'badan-usaha-1', 'payment', 'event-1', $this->balancedEntries());

        $this->assertSame(0, $begun);
        $this->assertSame($levelBefore, DB::transactionLevel());
    }

    public function test_the_caller_can_wrap_post_in_its_own_transaction(): void
    {
        $journal = new LedgerSeamStub;

        DB::transaction(function () use ($journal): void {
            $journal->post('payment:caller-transaction', 'badan-usaha-1', 'payment', 'event-1', $this->balancedEntries());
        });

        $this->assertSame([1], $journal->observedTransactionLevels());
    }

    /**
     * @return list<array{account: string, direction: string, amountMinor: int}>
     */
    private function balancedEntries(): array
    {
        return [
            ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 1_000],
            ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 1_000],
        ];
    }
}
