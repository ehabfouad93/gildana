"""
The choreography — what the video shows, in order.

Data, not logic. Each scene names itself, says which cuts it belongs to, and drives the app
through `Tour` (see record.py). Captions are referenced by key so wording lives in
captions.json and never gets edited in two places.

Two cuts share one set of scenes:

  tour   ~3 min, for a customer who just got their account. Task-shaped: here is how you
         import contacts, send a campaign, read the report, build an automation.
  promo  ~90 s, for a prospect who has not signed up. Only the scenes that sell, run faster,
         no sign-in and no settings.

SPEED scales every hold. The promo does not skip beats, it takes them quicker — which keeps
one recording honest instead of maintaining two.
"""

SPEED = {"tour": 1.0, "promo": 0.62}

BOTH = ["tour", "promo"]
TOUR = ["tour"]


# ── scenes ─────────────────────────────────────────────────────────────────────────────────
def s_open(t):
    t.chapter("ch_start", ms=2600)
    t.sign_in()
    t.say("signin", 2200)


def s_open_promo(t):
    """The promo skips the sign-in narration but still has to be signed in to show anything."""
    t.chapter("ch_start", ms=2400)
    t.sign_in()


def s_dashboard(t):
    t.goto("/client/index.php")
    t.say("dash", 2600)
    t.scroll(220)
    t.say("checklist", 2800)
    t.scroll(-220)


def s_contacts(t):
    t.chapter("ch_contacts")
    t.goto("/client/contacts.php")
    t.say("contacts_in", 2600)
    t.quiet()
    t.scroll(180)
    t.say("contacts_tags", 2800)

    # Pick a few rows by hand, so the bulk bar appears the way it does for a person.
    t.quiet()
    t.say("contacts_pick", 1500)
    for i in range(4):
        t.click("input.ck-row", index=i, settle=260)
    t.say("contacts_bulk", 2600)

    # …then turn the selection into a list.
    t.click("button:has-text('Add to list')")
    t.say("contacts_list", 2200)
    t.type("#bulk-new-list", "Hot — New Cairo")
    t.hold(900)
    t.click("#bulk-go")
    t.hold(1400)
    t.quiet()


def s_campaign(t):
    t.chapter("ch_campaign")
    t.goto("/client/campaign_new.php")
    t.say("camp_new", 2400)
    t.type('input[name="name"]', "New Cairo — floor plans", delay=60)

    t.say("camp_tpl", 2600)
    t.choose("#template_id", "new_cairo_launch")

    t.say("camp_list", 2000)
    t.choose("#list_id", "New Cairo — October launch")

    t.say("camp_send", 2200)
    t.scroll(320)
    t.move_to("#submit-btn")
    t.hold(900)
    t.quiet()


def s_report(t):
    """The report of the campaign the demo account already ran — real rows, real counts."""
    t.goto("/client/campaigns.php")
    t.hold(700)
    # Target the completed campaign's own row. Matching on href avoids the sidebar's
    # "Reports" link, and naming the row avoids opening the draft, whose report is all zeros.
    t.click("tr:has-text('New Cairo launch') a[href^='report.php']")
    t.say("report", 2800)
    t.scroll(260)
    t.say("report_fail", 2600)
    t.scroll(-260)
    t.quiet()


def s_inbox(t):
    t.chapter("ch_inbox")
    t.goto("/client/inbox.php")
    t.hold(1200)
    t.say("inbox", 2600)
    t.click(".ib-threads .ib-av", index=1, settle=1200)
    t.say("inbox_reply", 2400)
    t.type("#ib-text", "Friday 11am works — I'll confirm the address.", delay=55)
    t.hold(1100)
    t.quiet()


def s_automation(t):
    t.chapter("ch_auto")
    t.goto("/client/automations.php")
    t.hold(700)
    # "Edit" on the client's own flow — the cards above it are the one-click starters.
    t.click("a[href^='automation_edit.php']", settle=2200)

    # The editor's own full-screen mode is the right shot: the whole flow in frame with the
    # chrome out of the way, instead of a canvas half below the fold. FIT then sizes the graph
    # to the window so no node hangs off the edge.
    t.click("#btn-focus", settle=1200)
    t.click("button:has-text('FIT')", settle=900)
    t.say("auto_canvas", 3000)
    t.say("auto_stats", 2800)
    t.quiet()
    t.pg.keyboard.press("Escape")          # back to the full page for Preview and Check
    t.hold(800)

    # Preview — the part that matters most, and the part nobody believes until they see it.
    t.click("button:has-text('Preview this flow')", settle=1800)
    t.say("auto_preview", 3000)
    t.type("#pv-answer", "New Cairo", delay=80)
    t.click("#pv-answer + button", settle=1500)
    t.say("auto_preview_type", 2600)
    t.type("#pv-answer", "Around 5 million", delay=80)
    t.click("#pv-answer + button", settle=1800)
    t.hold(900)
    t.click("button:has-text('Close')", settle=900)

    t.click("button:has-text('Check for problems')", settle=1600)
    t.say("auto_check", 2800)
    t.quiet()


def s_qualifier(t):
    t.chapter("ch_qual")
    t.goto("/client/qualifiers.php")
    t.hold(800)
    t.say("qual", 3000)
    t.click("a[href^='leads.php']", settle=1600)
    t.say("qual_grades", 2800)
    t.quiet()

    # A scored lead — the ones still mid-conversation have an empty transcript by definition,
    # and "No messages yet" is not what this caption is claiming.
    t.click("tr:has-text('Scored') button:has-text('Transcript')", settle=1400)
    t.say("qual_transcript", 2600)
    t.click("#m-chat button:has-text('Close')", settle=700)
    t.quiet()


def s_guide(t):
    t.chapter("ch_help")
    t.goto("/client/contacts.php")
    t.hold(600)
    t.click(".guide-btn", settle=1200)
    t.say("help_guide", 3200)
    t.hold(1400)
    t.quiet()


def s_end_tour(t):
    t.goto("/client/index.php")
    t.say("end_tour", 3200)


def s_end_promo(t):
    t.goto("/client/index.php")
    t.say("end_promo", 3200)


SCENES = [
    {"id": "open",       "cuts": TOUR,  "run": s_open},
    {"id": "open_promo", "cuts": ["promo"], "run": s_open_promo},
    {"id": "dashboard",  "cuts": TOUR,  "run": s_dashboard},
    {"id": "contacts",   "cuts": TOUR,  "run": s_contacts},
    {"id": "campaign",   "cuts": TOUR,  "run": s_campaign},
    {"id": "report",     "cuts": BOTH,  "run": s_report},
    {"id": "inbox",      "cuts": BOTH,  "run": s_inbox},
    {"id": "automation", "cuts": BOTH,  "run": s_automation},
    {"id": "qualifier",  "cuts": BOTH,  "run": s_qualifier},
    {"id": "guide",      "cuts": TOUR,  "run": s_guide},
    {"id": "end_tour",   "cuts": TOUR,  "run": s_end_tour},
    {"id": "end_promo",  "cuts": ["promo"], "run": s_end_promo},
]
