<?php

declare(strict_types=1);

namespace App\Services\Engagement;

final class XTweetDisplayNormalizer
{
    /**
     * @param  array<string, mixed>  $tweet
     * @param  array<string, mixed>  $rawIncludes
     * @return array{text: string, media: list<array<string, mixed>>}
     */
    public function normalize(array $tweet, array $rawIncludes = []): array
    {
        return [
            'text' => $this->cleanText($tweet),
            'media' => $this->mediaContext($tweet, $this->indexedIncludes($rawIncludes)),
        ];
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function cleanText(array $tweet): string
    {
        $text = (string) ($tweet['text'] ?? '');
        if ($text === '') {
            return '';
        }

        $ranges = $this->removableUrlRanges($tweet, $text);
        if ($ranges === []) {
            return $this->normalizeText($this->fallbackCleanTrailingCardUrls($tweet, $text));
        }

        usort($ranges, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($ranges as $range) {
            $text = mb_substr($text, 0, $range['start']).mb_substr($text, $range['end']);
        }

        return $this->normalizeText($text);
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @return list<array{start: int, end: int}>
     */
    private function removableUrlRanges(array $tweet, string $text): array
    {
        $urls = $tweet['entities']['urls'] ?? [];
        if (! is_array($urls)) {
            return [];
        }

        $ranges = [];
        $trimmedLength = mb_strlen(rtrim($text));
        foreach ($urls as $url) {
            if (! is_array($url) || ! isset($url['start'], $url['end']) || ! is_numeric($url['start']) || ! is_numeric($url['end'])) {
                continue;
            }

            $start = (int) $url['start'];
            $end = (int) $url['end'];
            if ($start < 0 || $end <= $start || $end > mb_strlen($text)) {
                continue;
            }

            if ($this->isGeneratedCardUrl($tweet, $url, $end, $trimmedLength)) {
                $ranges[] = ['start' => $start, 'end' => $end];
            }
        }

        return $ranges;
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array<string, mixed>  $url
     */
    private function isGeneratedCardUrl(array $tweet, array $url, int $end, int $trimmedLength): bool
    {
        $displayUrl = strtolower((string) ($url['display_url'] ?? ''));
        $expandedUrl = strtolower((string) ($url['expanded_url'] ?? ''));
        $shortUrl = strtolower((string) ($url['url'] ?? ''));

        if (str_starts_with($displayUrl, 'pic.x.com/')
            || str_starts_with($displayUrl, 'pic.twitter.com/')
            || str_contains($expandedUrl, '/photo/')
            || str_contains($expandedUrl, '/video/')) {
            return true;
        }

        $quotedTweetId = $this->quotedTweetId($tweet);
        if ($quotedTweetId !== null
            && (str_contains($expandedUrl, '/status/'.$quotedTweetId)
                || str_contains($expandedUrl, '/i/web/status/'.$quotedTweetId))) {
            return true;
        }

        return $end === $trimmedLength
            && str_starts_with($shortUrl, 'https://t.co/')
            && ($this->hasMedia($tweet) || $quotedTweetId !== null)
            && (str_contains($displayUrl, 'x.com/')
                || str_contains($displayUrl, 'twitter.com/')
                || str_contains($expandedUrl, 'x.com/')
                || str_contains($expandedUrl, 'twitter.com/'));
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function fallbackCleanTrailingCardUrls(array $tweet, string $text): string
    {
        if (! $this->hasMedia($tweet) && $this->quotedTweetId($tweet) === null) {
            return $text;
        }

        return (string) preg_replace('/(?:\s+https:\/\/t\.co\/\S+)+\s*$/u', '', $text);
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function quotedTweetId(array $tweet): ?string
    {
        foreach ((array) ($tweet['referenced_tweets'] ?? []) as $reference) {
            if (is_array($reference) && ($reference['type'] ?? null) === 'quoted' && isset($reference['id'])) {
                return (string) $reference['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tweet
     */
    private function hasMedia(array $tweet): bool
    {
        return is_array($tweet['attachments'] ?? null)
            && is_array($tweet['attachments']['media_keys'] ?? null)
            && $tweet['attachments']['media_keys'] !== [];
    }

    /**
     * @param  array<string, mixed>  $tweet
     * @param  array{media: array<string, array<string, mixed>>}  $includes
     * @return list<array<string, mixed>>
     */
    private function mediaContext(array $tweet, array $includes): array
    {
        $keys = $tweet['attachments']['media_keys'] ?? [];
        if (! is_array($keys)) {
            return [];
        }

        $media = [];
        foreach (array_slice($keys, 0, 4) as $key) {
            $item = $includes['media'][(string) $key] ?? null;
            if ($item === null) {
                continue;
            }

            $url = $item['url'] ?? $item['preview_image_url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            $media[] = [
                'type' => (string) ($item['type'] ?? 'photo'),
                'url' => $url,
                'alt_text' => isset($item['alt_text']) ? (string) $item['alt_text'] : null,
                'width' => isset($item['width']) ? (int) $item['width'] : null,
                'height' => isset($item['height']) ? (int) $item['height'] : null,
            ];
        }

        return $media;
    }

    /**
     * @param  array<string, mixed>  $rawIncludes
     * @return array{media: array<string, array<string, mixed>>}
     */
    private function indexedIncludes(array $rawIncludes): array
    {
        $indexed = [];
        foreach ((array) ($rawIncludes['media'] ?? []) as $row) {
            if (is_array($row) && isset($row['media_key'])) {
                $indexed[(string) $row['media_key']] = $row;
            }
        }

        return ['media' => $indexed];
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
