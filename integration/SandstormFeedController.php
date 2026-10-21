<?php

namespace Wallabag\CoreBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Wallabag\CoreBundle\Repository\EntryRepository;
use Wallabag\CoreBundle\Repository\TagRepository;

/** Authenticated RSS exports; Sandstorm owns credentials and revocation. */
final class SandstormFeedController extends AbstractController
{
    /** @Route("/sandstorm/feeds", name="sandstorm_feeds", methods={"GET"}) */
    public function settings(TagRepository $tags)
    {
        return $this->render('@WallabagCore/Sandstorm/feeds.html.twig', [
            'tags' => $tags->findAllFlatTagsWithNbEntries($this->getUser()->getId()),
        ]);
    }

    /**
     * @Route("/feeds/{type}.xml", name="sandstorm_feed", methods={"GET"}, requirements={"type"="all|unread|archive|starred"})
     */
    public function feed(Request $request, EntryRepository $repository, string $type)
    {
        // Reject array-shaped parameters before passing them to SQL or URL builders.
        foreach (['limit', 'page', 'tag', 'origin'] as $name) {
            if ($request->query->has($name) && !is_string($request->query->get($name))) {
                return new Response('Feed parameters must be strings.', 400);
            }
        }
        $limit = filter_var($request->query->get('limit', 50), FILTER_VALIDATE_INT);
        $page = filter_var($request->query->get('page', 1), FILTER_VALIDATE_INT);
        if (!$limit || $limit < 1 || $limit > 100 || !$page || $page < 1 || $page > 10000) {
            return new Response('Use limit=1..100 and page=1..10000.', 400);
        }
        $tag = $request->query->get('tag', '');
        // API sessions have no public host. This optional origin is link metadata,
        // never a fetch destination. Validate it before querying the library.
        $base = $request->query->get('origin', $request->headers->get('X-Sandstorm-Base-Path', ''));
        if ($base !== '' && (!filter_var($base, FILTER_VALIDATE_URL) ||
            !preg_match('#^https?://[^/?\\#@]+$#D', $base))) {
            return new Response('origin must be an HTTP(S) origin without credentials or a path.', 400);
        }
        // Never echo arbitrary query arguments (possibly credentials) into the feed.
        $params = ['limit' => $limit, 'page' => $page];
        if ($tag !== '') $params['tag'] = $tag;
        if ($base !== '') $params['origin'] = $base;
        $feedPath = '/feeds/' . $type . '.xml';
        $self = $base !== '' ? $base . $feedPath . '?' . http_build_query($params) : null;
        $methods = ['all' => 'getBuilderForAllByUser', 'unread' => 'getBuilderForUnreadByUser',
            'archive' => 'getBuilderForArchiveByUser', 'starred' => 'getBuilderForStarredByUser'];
        $query = $repository->{$methods[$type]}($this->getUser()->getId());
        if ($tag !== '') {
            $query->innerJoin('e.tags', 'feedTag')->andWhere('feedTag.slug = :feedTag')->setParameter('feedTag', $tag);
        }
        $entries = $query->orderBy('e.createdAt', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit + 1)->getQuery()->getResult();
        $hasNext = count($entries) > $limit;
        $entries = array_slice($entries, 0, $limit);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $rss = $doc->appendChild($doc->createElement('rss'));
        $rss->setAttribute('version', '2.0');
        $channel = $rss->appendChild($doc->createElement('channel'));
        // XML text nodes safely encode HTML, ampersands, and CDATA terminators.
        $text = static function ($parent, $name, $value) use ($doc) {
            $node = $parent->appendChild($doc->createElement($name));
            $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value);
            $node->appendChild($doc->createTextNode($value ?? ''));
            return $node;
        };
        $text($channel, 'title', 'wallabag — ' . $type . ($tag !== '' ? ' — ' . $tag : ''));
        $text($channel, 'link', $self ?? 'https://wallabag.org');
        $text($channel, 'description', 'Saved articles from your wallabag library.');
        $text($channel, 'generator', 'wallabag for Sandstorm');
        $text($channel, 'ttl', '30');
        $link = static function ($rel, $url) use ($doc, $channel) {
            $node = $channel->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link'));
            $node->setAttribute('rel', $rel);
            $node->setAttribute('type', 'application/rss+xml');
            $node->setAttribute('href', $url);
        };
        if ($self !== null) $link('self', $self);
        if ($hasNext && $page < 10000 && $base !== '') {
            $params['page'] = $page + 1;
            $link('next', $base . $feedPath . '?' . http_build_query($params));
        }
        // Stable across API tokens and feed filters, distinct for each library.
        $namespace = substr(hash('sha256', $this->getParameter('secret')), 0, 32);
        foreach ($entries as $entry) {
            $item = $channel->appendChild($doc->createElement('item'));
            $text($item, 'title', $entry->getTitle());
            $text($item, 'link', $entry->getUrl());
            $guid = $text($item, 'guid', 'urn:wallabag:' . $namespace . ':entry:' . $entry->getId());
            $guid->setAttribute('isPermaLink', 'false');
            $text($item, 'pubDate', $entry->getCreatedAt()->format(DATE_RSS));
            $text($item, 'description', $entry->getContent());
            foreach ($entry->getTags() as $entryTag) {
                $text($item, 'category', $entryTag->getLabel());
            }
        }
        return new Response($doc->saveXML(), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
