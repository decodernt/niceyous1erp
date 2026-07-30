<?php
/**
 * Standalone test for ADDON_NICEYOUS1ERP_PAYLOADS::expenses().
 * No database required — payloads.class.php is dependency-free.
 *
 * Run:  php tests/PayloadsExpensesTest.php
 */

require_once __DIR__ . '/../library/payloads.class.php';

echo "=== EXPANAL expenses() Tests ===\n\n";

$failures = 0;

function assert_eq($label, $actual, $expected) {
    global $failures;
    if ($actual === $expected) {
        echo "  ✓ {$label}\n";
    } else {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        echo "  ✗ {$label}\n    expected: {$e}\n    actual:   {$a}\n";
        $failures++;
    }
}

$cfg = ADDON_NICEYOUS1ERP_PAYLOADS::defaultConfig();

// COD fee comes from the order amount passed in, never from config
assert_eq('codFeeAmount removed from defaultConfig', array_key_exists('codFeeAmount', $cfg), false);

// Shipping only, no COD
[$rows, $lineNum] = ADDON_NICEYOUS1ERP_PAYLOADS::expenses(4.96, false, 0.0, 9000002, $cfg);
assert_eq('shipping only: one row', count($rows), 1);
assert_eq('shipping only: expense code', $rows[0]['EXPN'], '104');
assert_eq('shipping only: net of 24% VAT', $rows[0]['SOVAL'], 4.0);
assert_eq('shipping only: line number continues', $rows[0]['LINENUM'], 9000003);
assert_eq('shipping only: nextLineNum', $lineNum, 9000003);

// COD order with the fee actually charged on the order (2.50 inc VAT)
[$rows] = ADDON_NICEYOUS1ERP_PAYLOADS::expenses(4.96, true, 2.50, 9000002, $cfg);
assert_eq('cod: two rows', count($rows), 2);
assert_eq('cod: expense code', $rows[1]['EXPN'], '105');
assert_eq('cod: fee net of 24% VAT', $rows[1]['SOVAL'], 2.02);
assert_eq('cod: EXPVAL matches SOVAL', $rows[1]['EXPVAL'], $rows[1]['SOVAL']);
assert_eq('cod: line numbers sequential', $rows[1]['LINENUM'], 9000004);

// COD order where no fee was charged (e.g. free over threshold) books nothing
[$rows] = ADDON_NICEYOUS1ERP_PAYLOADS::expenses(4.96, true, 0.0, 9000002, $cfg);
assert_eq('cod with zero order fee: no COD row', count($rows), 1);

// Non-COD order with an additional cost on the order books no COD row
[$rows] = ADDON_NICEYOUS1ERP_PAYLOADS::expenses(4.96, false, 2.50, 9000002, $cfg);
assert_eq('non-cod with order fee: no COD row', count($rows), 1);

// Free shipping + COD fee only
[$rows, $lineNum] = ADDON_NICEYOUS1ERP_PAYLOADS::expenses(0.0, true, 1.24, 9000005, $cfg);
assert_eq('cod only: one row', count($rows), 1);
assert_eq('cod only: expense code', $rows[0]['EXPN'], '105');
assert_eq('cod only: net fee', $rows[0]['SOVAL'], 1.0);
assert_eq('cod only: nextLineNum', $lineNum, 9000006);

// Nothing to book
[$rows, $lineNum] = ADDON_NICEYOUS1ERP_PAYLOADS::expenses(0.0, false, 0.0, 9000002, $cfg);
assert_eq('no expenses: empty rows', $rows, []);
assert_eq('no expenses: lineNum unchanged', $lineNum, 9000002);

echo "\n" . ($failures === 0 ? "All tests passed.\n" : "{$failures} test(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
