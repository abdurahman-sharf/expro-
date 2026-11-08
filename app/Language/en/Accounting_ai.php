<?php

return [
    'finding_ai_backdated' => 'Journal {number} is dated {days_back} day(s) before it was posted, in a period that was closed until {lock_until} just afterwards.',
    'finding_ai_balance_jump' => 'The balance of {customer} rose from {prior} to {balance} (up {increase}) in the last {days} days.',
    'finding_ai_control_manual' => 'Manual journal {number} has {accounts} line(s) on accounts that customers, suppliers or stock locations own, such as {account}.',
    'finding_ai_duplicate' => 'Journal {number} repeats journal {earlier}: the same amounts on the same accounts, {days} day(s) apart, for {amount}.',
    'finding_ai_offhours' => 'Journal {number} was posted on {posted_at}, outside working hours, in a branch where only {share}% of the last {history} manual journals were.',
    'finding_ai_reversal_chain' => 'The journals of {source} {document} were reversed {reversals} times.',
    'finding_ai_round' => 'Journal {number} is a round amount of {amount}, a multiple of {unit}.',
    'journal_reclassify' => 'Reclassification suggested by the agent: {text}, from {from} to {to} (journal {number})',
    'journal_recurring' => 'Recurring entry suggested by the agent: {text}',
    'label_recurring' => 'Recurring expense {account}',
    'rationale_reclassify' => 'Manual journal {number} put {amount} on {from} with the text “{text}”. The same text went to {to} {uses} times out of {total} before ({share}%). The agent suggests moving the amount to {to}. Nothing is posted until a person approves.',
    'rationale_recurring' => '{account} ({text}) was posted in each of the last {months} months for about the same amount, and nothing has been posted for {month}. The agent suggests repeating journal {number} of the last month, dated today. Nothing is posted until a person approves.',
    'title_reclassify' => 'Move {amount} from {from} to {to}',
    'title_recurring' => 'Repeat {amount} on {account} for {month}',
];
