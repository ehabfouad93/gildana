-- Ready-made automations a client can install in one click.
--
-- A new client opening the builder sees an empty canvas and, in practice, closes it again.
-- These are working flows they can install and edit, which is a far better starting point
-- than a blank page and a node menu.
--
-- Stored as a graph of steps in JSON. Installing clones them into flows/flow_steps for that
-- client, so an edited copy is theirs alone and the template stays pristine.
CREATE TABLE IF NOT EXISTS flow_templates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(40)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    summary     VARCHAR(255) NOT NULL DEFAULT '',
    trigger_type VARCHAR(24) NOT NULL DEFAULT 'keyword',
    graph       JSON         NOT NULL,
    sort        INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_ft_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO flow_templates (code, name, summary, trigger_type, sort, graph) VALUES
('welcome_qualify', 'Welcome & qualify',
 'Greets someone who messages for the first time, asks what they need, and tags them so you can follow up.',
 'welcome', 1,
 '{"steps":[
   {"k":"greet","type":"text","cfg":{"body":"Hi {{name}}! Thanks for getting in touch. How can we help today?"},"next":"ask"},
   {"k":"ask","type":"question","cfg":{"body":"What are you looking for?","save_as":"need"},"next":"tag"},
   {"k":"tag","type":"tag","cfg":{"tag":"enquiry"},"next":"thanks"},
   {"k":"thanks","type":"text","cfg":{"body":"Thank you — someone from the team will reply shortly."},"next":null}
 ]}'),

('faq_menu', 'Answer common questions',
 'Offers a menu of your most-asked questions so people get an answer instantly instead of waiting.',
 'keyword', 2,
 '{"steps":[
   {"k":"menu","type":"list_msg","cfg":{"body":"What would you like to know?","button":"See options","header":"Help",
     "options":[{"title":"Opening hours","description":"When we are open"},
                {"title":"Where you are","description":"Our address"},
                {"title":"Prices","description":"What things cost"},
                {"title":"Talk to a person","description":"Someone will reply"}]},"next":"reply"},
   {"k":"reply","type":"text","cfg":{"body":"Thanks — one moment while we get that for you."},"next":null}
 ]}'),

('abandoned_cart', 'Abandoned cart reminder',
 'Waits a while, then nudges someone who did not finish their order — and stops if they reply.',
 'csv', 3,
 '{"steps":[
   {"k":"wait","type":"wait","cfg":{"seconds":3600},"next":"nudge"},
   {"k":"nudge","type":"text","cfg":{"body":"Hi {{name}}, you left something in your basket. Would you like a hand finishing your order?"},"next":"ask"},
   {"k":"ask","type":"buttons","cfg":{"body":"Shall we hold it for you?","buttons":[{"title":"Yes please"},{"title":"No thanks"}]},"next":null}
 ]}'),

('booking_reminder', 'Booking reminder',
 'Sends a reminder the morning of an appointment and lets people confirm or reschedule.',
 'csv', 4,
 '{"steps":[
   {"k":"until","type":"wait_until","cfg":{"time":"09:00","weekday":null},"next":"remind"},
   {"k":"remind","type":"text","cfg":{"body":"Good morning {{name}} — a reminder about your appointment today."},"next":"confirm"},
   {"k":"confirm","type":"buttons","cfg":{"body":"Are you still able to make it?","buttons":[{"title":"Yes, see you"},{"title":"Reschedule"}]},"next":null}
 ]}'),

('lead_to_sheet', 'Capture leads to a sheet',
 'Asks for the details you need and writes each new lead into a Google Sheet.',
 'keyword', 5,
 '{"steps":[
   {"k":"hi","type":"text","cfg":{"body":"Happy to help! Just a couple of quick questions."},"next":"q1"},
   {"k":"q1","type":"question","cfg":{"body":"What is your name?","save_as":"lead_name"},"next":"q2"},
   {"k":"q2","type":"question","cfg":{"body":"And which city are you in?","save_as":"city"},"next":"save"},
   {"k":"save","type":"collect","cfg":{"sheet_name":"Leads","fields":["phone","name","last_reply","score","tags"]},"next":"done"},
   {"k":"done","type":"text","cfg":{"body":"Thank you {{lead_name}} — we will be in touch shortly."},"next":null}
 ]}');
