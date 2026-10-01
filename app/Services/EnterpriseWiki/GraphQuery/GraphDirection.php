<?php

namespace App\Services\EnterpriseWiki\GraphQuery;

/**
 * Which way a focus traversal is allowed to walk.
 *
 * The projection stores one directed WIKILINK per SQL link row, so "incoming" is a real
 * question about the graph and not merely a display option: it answers "what links here",
 * which is what a maintainer asks before changing or retiring a page.
 */
enum GraphDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
    case Both = 'both';
}
