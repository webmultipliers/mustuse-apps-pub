<?php

declare(strict_types=1);

namespace MustUse\Pub\Admin\Seeders;

/**
 * Static fixture data for the content-showcase demo.
 *
 * Keeping copy in one place with no randomness means `seed()` is a
 * pure function — identical output across runs, trivially diffable,
 * no "why did the titles change?" surprises. Publishers replace
 * anything they don't like by editing the seeded posts after the
 * scaffold runs.
 */
final class DemoCopy
{
    /**
     * @return list<array{slug: string, name: string, description: string}>
     */
    public static function categories(): array
    {
        return [
            [ 'slug' => 'demo-news',     'name' => 'News',     'description' => 'Daily reporting and updates.' ],
            [ 'slug' => 'demo-culture',  'name' => 'Culture',  'description' => 'Arts, film, music, and books.' ],
            [ 'slug' => 'demo-tech',     'name' => 'Tech',     'description' => 'Product reviews, deep dives, and explainers.' ],
            [ 'slug' => 'demo-business', 'name' => 'Business', 'description' => 'Markets, startups, and economic analysis.' ],
            [ 'slug' => 'demo-travel',   'name' => 'Travel',   'description' => 'Guides, photo essays, and trip reports.' ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    public static function tags(): array
    {
        return [
            [ 'slug' => 'demo-trending',     'name' => 'Trending' ],
            [ 'slug' => 'demo-featured',     'name' => 'Featured' ],
            [ 'slug' => 'demo-weekend',      'name' => 'Weekend Read' ],
            [ 'slug' => 'demo-editors-pick', 'name' => "Editor's Pick" ],
            [ 'slug' => 'demo-interview',    'name' => 'Interview' ],
            [ 'slug' => 'demo-review',       'name' => 'Review' ],
            [ 'slug' => 'demo-guide',        'name' => 'Guide' ],
            [ 'slug' => 'demo-opinion',      'name' => 'Opinion' ],
        ];
    }

    /**
     * 15 posts (3 per category) with curated tags. `day_offset` spreads
     * publish dates across the last 30 days so "latest" and
     * "trending" order come out looking natural.
     *
     * @return list<array{
     *     slug: string,
     *     title: string,
     *     excerpt: string,
     *     category: string,
     *     tags: list<string>,
     *     day_offset: int,
     *     body: list<array{type: string, text: string}>,
     * }>
     */
    public static function posts(): array
    {
        return [
            // News
            [
                'slug'       => 'demo-city-approves-harbor-plan',
                'title'      => 'City council approves harbor redevelopment plan',
                'excerpt'    => 'A 15-year blueprint to revitalise the waterfront cleared the final vote last night.',
                'category'   => 'demo-news',
                'tags'       => [ 'demo-trending', 'demo-featured' ],
                'day_offset' => 1,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'After eighteen months of public consultation, the council voted 8-3 in favour of the harbor redevelopment plan. Supporters described it as the largest public works initiative in a generation.' ],
                    [ 'type' => 'h', 'text' => "What's in the plan" ],
                    [ 'type' => 'p', 'text' => 'The phased rollout covers three kilometres of waterfront, a new pedestrian promenade, mixed-use buildings, and two transit stops. Phase one breaks ground next spring.' ],
                    [ 'type' => 'p', 'text' => 'Opponents raised concerns about traffic displacement and rising rents. The council committed to publishing quarterly progress reports and an independent equity audit.' ],
                ],
            ],
            [
                'slug'       => 'demo-rail-extension-opens',
                'title'      => 'Light-rail extension opens two weeks ahead of schedule',
                'excerpt'    => 'Service to the Northfield district begins Friday, linking 40,000 residents to the downtown core.',
                'category'   => 'demo-news',
                'tags'       => [ 'demo-editors-pick' ],
                'day_offset' => 3,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'The four-station extension into Northfield enters public service on Friday morning, culminating a three-year build that narrowly avoided pandemic-era delays.' ],
                    [ 'type' => 'p', 'text' => 'Transit officials expect ridership to climb 12% in the first quarter as residents shift from bus connections to the new direct line.' ],
                ],
            ],
            [
                'slug'       => 'demo-grant-funds-research',
                'title'      => 'Federal grant funds regional climate research hub',
                'excerpt'    => 'A $42m award launches a ten-year program studying coastal resilience.',
                'category'   => 'demo-news',
                'tags'       => [ 'demo-featured' ],
                'day_offset' => 7,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'The National Science Foundation announced a $42 million grant to establish a regional climate research hub anchored at two state universities. The program aims to pair academic research with local policy-making.' ],
                    [ 'type' => 'h', 'text' => 'Research pillars' ],
                    [ 'type' => 'p', 'text' => 'Initial work focuses on sea-level rise modelling, stormwater infrastructure, and migration patterns of affected communities. Researchers from six institutions have already signed on.' ],
                ],
            ],

            // Culture
            [
                'slug'       => 'demo-documentary-festival-lineup',
                'title'      => 'Documentary festival unveils a lineup built on risk',
                'excerpt'    => 'Forty-two features, many without distributors, anchor this year\'s programme.',
                'category'   => 'demo-culture',
                'tags'       => [ 'demo-review', 'demo-weekend' ],
                'day_offset' => 2,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'This year\'s festival programme leans hard into independent work — 42 features, 18 of them without a distributor, and a significant jump in directorial debuts.' ],
                    [ 'type' => 'p', 'text' => 'Standout premieres include a three-hour meditation on grain farming, an oral history of shortwave radio operators, and a verité profile of a rural hospital nightshift.' ],
                ],
            ],
            [
                'slug'       => 'demo-novelist-interview',
                'title'      => 'A novelist on writing twelve drafts of the same opening chapter',
                'excerpt'    => 'An interview about obsession, revision, and knowing when to stop.',
                'category'   => 'demo-culture',
                'tags'       => [ 'demo-interview', 'demo-editors-pick' ],
                'day_offset' => 5,
                'body'       => [
                    [ 'type' => 'p', 'text' => '"I kept thinking the next pass would be the one," she says. "Draft twelve wasn\'t. Draft thirteen was."' ],
                    [ 'type' => 'h', 'text' => 'On revision' ],
                    [ 'type' => 'p', 'text' => 'She describes the cycle bluntly: write, print, mark it up in red, retype, discard the retype, write again from memory. The physical act of re-typing, she argues, forces you to hear sentences fresh.' ],
                ],
            ],
            [
                'slug'       => 'demo-album-review',
                'title'      => 'A patient, maximal album that finally earns its runtime',
                'excerpt'    => 'Seventy-eight minutes that never drag — a review.',
                'category'   => 'demo-culture',
                'tags'       => [ 'demo-review' ],
                'day_offset' => 9,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'Few records clock in over seventy minutes without testing a listener\'s patience. This one earns every second — patient, maximal, and willing to trust the audience to follow its pivots.' ],
                    [ 'type' => 'p', 'text' => 'The standout is the sixth track: a seven-minute excursion that swaps signature every two bars until the form collapses into a single held note.' ],
                ],
            ],

            // Tech
            [
                'slug'       => 'demo-usb-c-hubs',
                'title'      => 'We tested 14 USB-C hubs so you don\'t have to',
                'excerpt'    => 'The cheap ones fail in interesting ways. The expensive ones fail differently.',
                'category'   => 'demo-tech',
                'tags'       => [ 'demo-review', 'demo-guide', 'demo-featured' ],
                'day_offset' => 4,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'After six weeks, fourteen hubs, and more thermal-imaging footage than anyone should produce in a single quarter: none of them are great. Most are fine. Three are actively dangerous under sustained load.' ],
                    [ 'type' => 'h', 'text' => 'Methodology' ],
                    [ 'type' => 'p', 'text' => 'We measured charge throughput, display refresh stability under 4K output, thermal behaviour at sustained 80W draw, and real-world data-transfer speeds across a 40-hour workload.' ],
                ],
            ],
            [
                'slug'       => 'demo-local-first-software',
                'title'      => 'A case for local-first software in a cloud-everything era',
                'excerpt'    => 'What you gain when your data lives on the device, not the server.',
                'category'   => 'demo-tech',
                'tags'       => [ 'demo-opinion', 'demo-editors-pick' ],
                'day_offset' => 11,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'The pendulum is swinging. After fifteen years of cloud-everything, developers are rediscovering the quiet satisfactions of local-first: ownership, responsiveness, offline capability, and privacy by default.' ],
                    [ 'type' => 'p', 'text' => 'CRDTs make the sync problem tractable. Small apps built on them ship faster, feel better, and last longer.' ],
                ],
            ],
            [
                'slug'       => 'demo-bluetooth-primer',
                'title'      => 'A primer on modern Bluetooth audio codecs',
                'excerpt'    => 'SBC, AAC, aptX, LDAC — what they trade and why it matters.',
                'category'   => 'demo-tech',
                'tags'       => [ 'demo-guide' ],
                'day_offset' => 14,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'Every Bluetooth headphone on the market picks one of a small number of codecs for its audio stream. The picks are load-bearing — they decide how the audio sounds, how much battery you burn, and how stable the connection stays in crowded RF environments.' ],
                    [ 'type' => 'p', 'text' => 'The short version: SBC is the floor; AAC works well on Apple devices; aptX variants excel on Android; LDAC pushes the most bits through when conditions allow.' ],
                ],
            ],

            // Business
            [
                'slug'       => 'demo-small-business-quarter',
                'title'      => 'Small businesses log their strongest quarter in three years',
                'excerpt'    => 'Regional data suggests the rebound is broad-based.',
                'category'   => 'demo-business',
                'tags'       => [ 'demo-trending' ],
                'day_offset' => 6,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'Regional small-business revenue rose 8.4% year-over-year in the last quarter, the strongest figure since 2023. The rebound appears broad-based, with gains visible across hospitality, retail, and personal services.' ],
                ],
            ],
            [
                'slug'       => 'demo-venture-funding-report',
                'title'      => 'Venture funding stabilises after a two-year drawdown',
                'excerpt'    => 'Deal count rose 12% in the last month — and so did scrutiny.',
                'category'   => 'demo-business',
                'tags'       => [ 'demo-featured' ],
                'day_offset' => 10,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'After two years of tightening conditions, early-stage funding shows early signs of a floor. Deal count rose 12% month-over-month, though investors report longer diligence timelines and lower valuations across most stages.' ],
                ],
            ],
            [
                'slug'       => 'demo-cofounder-dispute',
                'title'      => 'The cofounder dispute that quietly broke a unicorn',
                'excerpt'    => 'A case study in governance gone wrong.',
                'category'   => 'demo-business',
                'tags'       => [ 'demo-weekend', 'demo-editors-pick' ],
                'day_offset' => 16,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'Internal documents reviewed over three months reveal how a seemingly healthy $1.8bn company unraveled in eight weeks. The trigger wasn\'t product, market, or funding — it was a governance gap the founders never fixed.' ],
                    [ 'type' => 'h', 'text' => 'Lessons' ],
                    [ 'type' => 'p', 'text' => 'The dispute is instructive because nothing about it was unusual. Most early-stage teams ship the same pattern of hand-shake governance. Most of them are lucky enough not to stress-test it.' ],
                ],
            ],

            // Travel
            [
                'slug'       => 'demo-off-season-coast',
                'title'      => 'In praise of the off-season coast',
                'excerpt'    => 'Empty beaches, open tables, and the quiet you were told wasn\'t possible.',
                'category'   => 'demo-travel',
                'tags'       => [ 'demo-weekend' ],
                'day_offset' => 8,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'Travel in the off-season is one of those ideas everyone agrees with in principle and nobody actually does. This is an argument for actually doing it.' ],
                    [ 'type' => 'p', 'text' => 'The restaurants are open, the beaches are empty, the staff are relaxed, and the weather — with a pair of decent shoes and a good jacket — is most of what you wanted anyway.' ],
                ],
            ],
            [
                'slug'       => 'demo-train-travel-guide',
                'title'      => 'A practical guide to crossing a continent by train',
                'excerpt'    => 'Eight countries, twelve trains, one carry-on. What works and what doesn\'t.',
                'category'   => 'demo-travel',
                'tags'       => [ 'demo-guide', 'demo-featured' ],
                'day_offset' => 13,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'Three weeks, eight countries, twelve trains. The trip was easier than every guidebook suggested and harder in ways none of them warned about.' ],
                    [ 'type' => 'h', 'text' => 'What worked' ],
                    [ 'type' => 'p', 'text' => 'Flexible tickets. A single carry-on bag. Booking sleeper bunks in advance. A physical map for when roaming data failed (which it did, twice, in unexpected places).' ],
                ],
            ],
            [
                'slug'       => 'demo-island-photo-essay',
                'title'      => 'A photo essay from a small volcanic island',
                'excerpt'    => 'Fourteen frames from three days ashore.',
                'category'   => 'demo-travel',
                'tags'       => [ 'demo-review' ],
                'day_offset' => 20,
                'body'       => [
                    [ 'type' => 'p', 'text' => 'Three days, fourteen keepers. The island is small enough to walk end-to-end in a morning, and photogenic enough that you won\'t — you\'ll stop every hundred metres because the light has changed again.' ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{slug: string, title: string, body: list<array{type: string, text: string}>}>
     */
    public static function pages(): array
    {
        return [
            [
                'slug'  => 'demo-about',
                'title' => 'About this publication',
                'body'  => [
                    [ 'type' => 'p', 'text' => 'This is a seeded demo for the MustUse Apps Publisher. Every post, category, and tag on the site was created by clicking "Create content-showcase app" in the WordPress admin.' ],
                    [ 'type' => 'p', 'text' => 'Edit this page to replace the default copy with your real About content. The mobile app\'s "About" screen reads from here.' ],
                ],
            ],
            [
                'slug'  => 'demo-contact',
                'title' => 'Contact',
                'body'  => [
                    [ 'type' => 'p', 'text' => 'Replace this page with your real contact details. Email, social links, submission guidelines — whatever your readers need.' ],
                ],
            ],
        ];
    }

    /**
     * Compose a seeded post's `post_content` as Gutenberg blocks so
     * `post-content` renders the body with its expected structure.
     *
     * @param list<array{type: string, text: string}> $blocks
     */
    public static function composeBody(array $blocks): string
    {
        $out = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'h') {
                $out[] = "<!-- wp:heading {\"level\":3} -->\n<h3>" . esc_html($block['text']) . "</h3>\n<!-- /wp:heading -->";
                continue;
            }
            $out[] = "<!-- wp:paragraph -->\n<p>" . esc_html($block['text']) . "</p>\n<!-- /wp:paragraph -->";
        }
        return \implode("\n\n", $out);
    }
}
