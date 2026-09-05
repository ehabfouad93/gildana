-- Backfill: add any of the twelve standard questions that are not already there.
--
-- Migration 026 seeds only into an EMPTY table, so an install that had already written its
-- own FAQ — even a single row — got none of them. That is the right call for 026 (it must
-- never flatten someone's work) but it leaves those installs with a landing page and a Help
-- centre carrying one question.
--
-- This adds the missing ones and touches nothing else. Matching is on the question text, so
-- a question the operator has reworded counts as theirs and is left alone, and running this
-- twice inserts nothing the second time. Their own entries keep their place; these land after
-- them by sort order.
--
-- One consequence worth stating: if you deliberately DELETED one of the twelve between 026
-- and this migration, it comes back. Hide it in Admin → Help Content instead of deleting it
-- and it will stay hidden.

INSERT INTO faq_items (sort, question, answer, status, created_at)
SELECT s.sort, s.question, s.answer, s.status, s.created_at
  FROM (

SELECT 10 AS sort,
'What does Revenect actually do?' AS question,
'It turns WhatsApp into a channel you can run a business on.

You send a campaign to a list of contacts, and everything after that is handled here too: the replies land in a live inbox, an automation can answer them, an AI agent can hold a real conversation using only the information you gave it, and every lead comes back scored hot, warm or cold.

Campaigns, automations, contacts, templates, the inbox and the reporting are all one dashboard. There is nothing to install and no developer needed.' AS answer,
'active' AS status, NOW() AS created_at

UNION ALL SELECT 20,
'Do I need an official WhatsApp Business API account?',
'No — you have two options, and you can start with either.

The official WhatsApp Business API — your business name and a green tick on every message, tappable reply buttons, delivery and read receipts, and no practical sending limit. It needs a Meta business account and an approved template for any message you send first. This is the right answer for most businesses.

The number you already use — scan a QR code with your phone and you are sending in minutes — no Meta account, no template approval, no waiting. Please read the next question before you choose this one.

Everything else in the product works the same on both.',
'active', NOW()

UNION ALL SELECT 30,
'Can I really send from my normal WhatsApp number? Is that safe?',
'You can, and we will be straight with you about the risk.

Sending in bulk from a personal WhatsApp number is against WhatsApp''s terms of service. WhatsApp detects unusual sending patterns, and a number can be restricted or banned for it. That is not a scare story, it genuinely happens.

What we do to reduce the risk: messages go out in small batches with a pause between them rather than in a flood, replies are never throttled, and you control the pace. What you should do: use a number you could afford to lose rather than your main business line, keep the volume sensible, only message people who expect to hear from you, and move to the official API once the volume justifies it.

If a banned number would be a disaster for you, use the official API from the start.',
'active', NOW()

UNION ALL SELECT 40,
'What is a credit, and what uses one up?',
'One credit is one message that goes out. That is the whole rule.

Messages you receive are free, and always will be. A reply that fails is refunded. Your plan includes an allowance of credits each month, and you can see exactly what has been spent, on what, in Billing — every credit is an entry in a ledger you can read, not a number that quietly goes down.

If you are on your own Meta account, WhatsApp bills you separately and directly for their per-message fee. That is between you and Meta at their published rates; none of it passes through us.',
'active', NOW()

UNION ALL SELECT 50,
'What is a template, and why does WhatsApp have to approve it?',
'On the official API, WhatsApp will not let a business message someone out of the blue with free text. The first message has to be a template: a message you wrote in advance and Meta approved, with blanks in it for the details that change — a name, an order number, a delivery date.

You write and submit templates in your Meta account; Revenect syncs the approved ones and fills the blanks in from each contact''s record when the campaign sends. Approval usually takes minutes to a few hours.

Once the customer replies, the template rule stops applying for 24 hours and you can write whatever you like. And on a personal number there are no templates at all — you just write the message.',
'active', NOW()

UNION ALL SELECT 60,
'What is the 24-hour rule?',
'On the official WhatsApp API, once a customer messages you, you have 24 hours to reply with anything you want — plain text, images, buttons, an AI conversation. Outside those 24 hours you can only send an approved template.

This matters when you build automations, and Revenect handles it for you rather than letting you find out from the silence. The flow checker warns you before you go live if a step would breach the window, and if a message is blocked by the rule it is held and shown to you rather than failing quietly.

On a personal number there is no such window.',
'active', NOW()

UNION ALL SELECT 70,
'Do I need my own AI key?',
'It depends on your plan, and either way there are no surprises.

If you bring your own key from OpenAI or Anthropic, the AI features work immediately and cost you exactly what your provider charges — we add nothing and count nothing.

On a plan that includes AI, you use ours and your usage is metered against a monthly allowance you can see in Billing. When that allowance runs out the AI step falls back to a message you wrote in advance and you are told — you will never be handed an unexpected bill.',
'active', NOW()

UNION ALL SELECT 80,
'How do my contacts get in?',
'Three ways, and you can mix them.

Upload a CSV or paste a list of numbers. Add people one at a time. Or connect a Google Sheet and let it keep feeding new rows in as your team fills them — useful when leads come from a form or an ad campaign.

Numbers are cleaned up and put into international format automatically, duplicates are merged rather than doubled, and anyone who replies STOP is opted out immediately and excluded from every campaign after that. An automation can also write results back out to a Google Sheet as it goes.',
'active', NOW()

UNION ALL SELECT 90,
'Can I test an automation before real customers see it?',
'Yes, and this is the part we are most pleased with.

Preview runs your flow as a real WhatsApp conversation on screen. You type answers as if you were the customer and watch which way it branches, with names and saved details filled in properly. It runs the real engine, so what you see is what will happen — but nothing is sent to anybody and no credits are spent.

Check for problems is the second half. Before you switch a flow on it looks for the mistakes that make a conversation die silently: a step nothing leads to, a question with no way out, an AI step with no fallback, a template that was deleted, a message the 24-hour rule would block.',
'active', NOW()

UNION ALL SELECT 100,
'What happens when a message fails?',
'It is retried, and if it still fails you are told exactly why.

Temporary problems — a timeout, a rate limit — are retried automatically, with a growing gap between attempts. Anything that gives up lands on a Needs attention page with the reason WhatsApp gave, so you can fix the number or the template and try again. Failed messages are refunded.

A message can never be sent or charged twice. Every attempt is recorded before the call goes out, so even if the server were to lose power mid-send, the message is either delivered once or held for you to look at — never sent again by accident.',
'active', NOW()

UNION ALL SELECT 110,
'Who can see my data, and is any of it deleted?',
'Your workspace is yours alone. Contacts, conversations, campaigns and automations are separated per account at the database level, and one customer cannot see or reach another''s.

Your WhatsApp access token and your AI key are encrypted before they are stored, and are never shown back on screen once saved.

Nothing of yours is deleted. Messages, campaign history, contacts, automations, credits and payments are kept for as long as your account exists, and there is no setting that prunes them. The only things trimmed are two raw diagnostic logs used for debugging the last few days, and both duplicate information that is kept permanently anyway.',
'active', NOW()

UNION ALL SELECT 120,
'How do I get started, and what do you need from me?',
'Send us the request form on our site and we will reply the same working day.

Setting up together takes about an hour. We will want to know which number you want to send from and whether you already have a Meta business account, roughly how many contacts you have and where they live now, and what the first thing you want to send actually is.

We connect the number, import your first list, and build one automation with you so you can see it work end to end. After that the dashboard is yours, and Help is a button on every page.',
'active', NOW()

) AS s
  LEFT JOIN faq_items f ON f.question = s.question
 WHERE f.id IS NULL;
