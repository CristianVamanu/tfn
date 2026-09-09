"""Curated (not AI-generated) starter roadmaps, keyed by business model.
Real AI-personalised generation is a planned upgrade - see ROADMAP.md.
"""

ROADMAP_TEMPLATES = {
    "AI Business": [
        ("Pick one narrow use case", "Not \"AI agency\" - a specific problem for a specific customer you can name."),
        ("Validate with 5 conversations", "Talk to 5 people who'd actually pay for it before building anything."),
        ("Build a fixed-scope offer", "Package it as a fixed-price, fixed-deliverable offer - not open-ended consulting."),
        ("Land your first client", "Outreach, a portfolio piece, or a paid pilot - get one real paying client."),
        ("Systemise delivery", "Turn what worked for client one into a repeatable checklist or template."),
        ("Raise your price for client two", "Once delivery is repeatable, the next client pays more for the same result."),
    ],
    "Personal Brand": [
        ("Choose your one topic", "Pick a specific angle you can talk about for a year without getting bored."),
        ("Post daily for 30 days", "Consistency before quality - most people quit before the algorithm even notices them."),
        ("Find your first 100 true fans", "People who reply, share, and would miss you if you stopped."),
        ("Package one thing to sell", "A guide, template, or service that follows naturally from what you post about."),
        ("Make your first sale", "To one of your 100 true fans, not a stranger."),
        ("Build a simple funnel", "Post -> free resource -> email list -> offer."),
    ],
    "YouTube": [
        ("Pick a format you can repeat", "A series format is easier to sustain than one-off videos."),
        ("Publish 10 videos before judging results", "The first 10 are for finding your voice and workflow, not going viral."),
        ("Study your own retention graphs", "Your own audience data teaches you more than any guide."),
        ("Double down on what works", "Make more of your best-performing video, not something totally different."),
        ("Add a non-AdSense income stream", "Sponsorship, affiliate, or your own product - ad revenue alone rarely works below 100k subs."),
        ("Build a posting system", "Batch-record and schedule so consistency doesn't depend on daily motivation."),
    ],
    "E-Commerce": [
        ("Pick one product, not a catalog", "A focused single-product store converts better than a general store early on."),
        ("Get 10 pre-launch signups or orders", "Validate demand before you sink money into inventory or ads."),
        ("Launch with organic traffic first", "Prove the offer converts before you pay to scale it with ads."),
        ("Get to your first 10 real customer orders", "Real orders, real feedback, real reviews."),
        ("Fix your worst drop-off point", "Usually checkout or shipping cost - find it in your own funnel data."),
        ("Turn on paid ads once organic converts", "Ads amplify a working funnel - they don't fix a broken one."),
    ],
    "Affiliate Business": [
        ("Pick one product you'd recommend anyway", "Trust converts better than a wide net of random offers."),
        ("Build one piece of real content about it", "A genuine review, comparison, or tutorial - not just a link."),
        ("Get it in front of 100 relevant people", "A forum, community, or platform where your audience already is."),
        ("Track clicks-to-sales, not just clicks", "Optimise for the conversion, not vanity traffic."),
        ("Add a second complementary offer", "Once one works, a related product to the same audience compounds it."),
        ("Build an email list from your traffic", "Owned audience beats being fully dependent on one platform's algorithm."),
    ],
    "Digital Products": [
        ("Solve a problem you've personally solved", "Your own hard-won experience is the fastest path to a credible product."),
        ("Pre-sell before you finish it", "Get 5 people to pay before building the whole thing."),
        ("Ship a minimum version", "A useful, finished, narrow product beats a polished, incomplete one."),
        ("Get your first 10 sales", "Focus on one distribution channel - your existing audience, a community, or a marketplace."),
        ("Collect and act on feedback", "Fix the biggest complaint before adding new features."),
        ("Build a simple upsell", "A companion product or higher tier for buyers who already trusted you once."),
    ],
    "Freelancing / Services": [
        ("Niche down to one service, one audience", "\"I help X do Y\" beats a general skills list."),
        ("Build a portfolio piece if you don't have one", "A spec project or discounted first client, done well, opens doors."),
        ("Land your first paid client", "Outreach beats waiting on marketplaces early on."),
        ("Deliver and ask for a testimonial", "Social proof is what gets you client two and three."),
        ("Raise your rate for the next client", "Each successful delivery justifies a higher price for the next one."),
        ("Systemise your intake process", "A simple process for scoping and onboarding saves you from scope creep."),
    ],
    "Software / SaaS": [
        ("Talk to 10 potential users before writing code", "Confirm the problem is real and painful before building a solution."),
        ("Build the smallest useful version", "One core workflow solved well, not a feature-complete product."),
        ("Get 5 people using it, even manually", "A spreadsheet-backed MVP that people actually use beats a polished unused app."),
        ("Charge someone before you scale", "One paying customer proves more than a hundred free signups."),
        ("Fix your highest-churn moment", "Find where users drop off and fix that before adding new features."),
        ("Build a repeatable acquisition channel", "One channel that reliably brings in the next 10 users."),
    ],
    "Trading": [
        ("Trade your own capital only", "Personal trading with your own money - no managing others' funds without proper registration."),
        ("Define your strategy and risk rules in writing", "Entry, exit, position size, and max loss - before you place a trade, not after."),
        ("Paper trade or size small until proven", "Prove the strategy on small size before increasing risk."),
        ("Keep a trade journal", "Log every trade and the reasoning - most edge is found by reviewing losses, not wins."),
        ("Review weekly against your own rules", "Did you follow your plan, regardless of outcome? That's the metric that matters."),
        ("Scale size only after a proven track record", "Increase risk gradually, tied to real, journaled performance - not confidence."),
    ],
    "Other": [
        ("Write down the specific problem you're solving", "For a specific person - not a general idea."),
        ("Talk to 5 people who have that problem", "Validate it's real and they'd pay for a solution before building."),
        ("Build the smallest version of your idea", "Something you can put in front of someone this week."),
        ("Get your first real user or customer", "Someone outside your friends and family."),
        ("Learn from what they actually do", "What they use, ignore, or ask for tells you more than what they say."),
        ("Decide what to double down on", "Based on real usage and feedback, not assumptions."),
    ],
}


def get_template(business_model: str):
    return ROADMAP_TEMPLATES.get(business_model, ROADMAP_TEMPLATES["Other"])
