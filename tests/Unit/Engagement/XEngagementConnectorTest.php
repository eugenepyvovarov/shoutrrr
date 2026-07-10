<?php

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\Connectors\XEngagementConnector;
use App\Services\Engagement\XTweetDisplayNormalizer;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function xAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111', 'handle' => '@owner']);
}

function xConnector(): XEngagementConnector
{
    return new XEngagementConnector(app(Factory::class), app(XTweetDisplayNormalizer::class));
}

test('fetchReplies parses the conversation search and resolves authors', function () {
    Http::fake([
        'api.twitter.com/2/tweets/search/recent*' => Http::response([
            'data' => [
                ['id' => '500', 'text' => 'root', 'author_id' => '111', 'created_at' => '2026-06-25T09:00:00.000Z'],
                ['id' => '900', 'text' => 'my reply', 'author_id' => '111', 'created_at' => '2026-06-25T10:00:00.000Z', 'referenced_tweets' => [['type' => 'replied_to', 'id' => '500']]],
                ['id' => '901', 'text' => 'great https://t.co/card', 'author_id' => '222', 'created_at' => '2026-06-25T10:00:00.000Z', 'in_reply_to_user_id' => '111', 'referenced_tweets' => [['type' => 'replied_to', 'id' => '900']], 'attachments' => ['media_keys' => ['3_1']], 'entities' => ['urls' => [['url' => 'https://t.co/card', 'display_url' => 'pic.x.com/card', 'expanded_url' => 'https://x.com/devAlex/status/901/photo/1', 'start' => 6, 'end' => 23]]]],
            ],
            'includes' => ['users' => [
                ['id' => '111', 'username' => 'owner', 'name' => 'Owner'],
                ['id' => '222', 'username' => 'fan', 'name' => 'Fan', 'profile_image_url' => 'http://a/p.jpg'],
            ], 'media' => [
                ['media_key' => '3_1', 'type' => 'photo', 'url' => 'https://pbs.twimg.com/media/reply.jpg', 'alt_text' => 'reply image', 'width' => 640, 'height' => 480],
            ]],
        ]),
        '*liked_tweets*' => Http::response([
            'data' => [['id' => '901']],
        ]),
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::X, 'remote_id' => '500', 'remote_ids' => ['500']]);

    $result = xConnector()->fetchReplies(xAccount(), $target, ['access_token' => 't'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->replies)->toHaveCount(2);
    expect($result->replies[0]->remoteReplyId)->toBe('900');
    expect($result->replies[0]->parentRemoteId)->toBe('500');
    expect($result->replies[0]->authorHandle)->toBe('owner');
    expect($result->replies[1]->remoteReplyId)->toBe('901');
    expect($result->replies[1]->parentRemoteId)->toBe('900');
    expect($result->replies[1]->authorHandle)->toBe('fan');
    expect($result->replies[1]->authorAvatarUrl)->toBe('http://a/p.jpg');
    expect($result->replies[1]->text)->toBe('great');
    expect($result->replies[1]->media[0]['url'])->toBe('https://pbs.twimg.com/media/reply.jpg');
    expect($result->replies[1]->isLiked)->toBeTrue();
    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['query'] ?? null) === 'conversation_id:500';
    });
});

test('display normalization decodes HTML entities from X text', function () {
    $normalized = app(XTweetDisplayNormalizer::class)->normalize([
        'text' => 'codex-&gt;chatgpt &amp; more',
    ]);

    expect($normalized['text'])->toBe('codex->chatgpt & more');
});

test('fetchReplies maps 403 to unsupported (no paid tier)', function () {
    Http::fake(['api.twitter.com/2/tweets/search/recent*' => Http::response(['title' => 'Forbidden'], 403)]);

    $result = xConnector()->fetchReplies(xAccount(), PostTarget::factory()->create(['platform' => Platform::X, 'remote_id' => '500']), ['access_token' => 't'], null);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
});

test('postReply posts an in_reply_to tweet', function () {
    Http::fake(['api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '999']])]);

    $parent = PostTargetReply::factory()->create(['platform' => Platform::X, 'remote_reply_id' => '900', 'remote_cid' => null]);

    $result = xConnector()->postReply(xAccount(), $parent, 'thanks', ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteReplyId)->toBe('999');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/2/tweets')
        && $req['reply']['in_reply_to_tweet_id'] === '900');
});

test('likeReply calls the X likes endpoint', function () {
    Http::fake(['api.twitter.com/2/users/111/likes' => Http::response(['data' => ['liked' => true]])]);

    $reply = PostTargetReply::factory()->create(['platform' => Platform::X, 'remote_reply_id' => '900']);

    $result = xConnector()->likeReply(xAccount(), $reply, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['tweet_id'] === '900');
});

test('unlikeReply calls the X likes endpoint', function () {
    Http::fake(['api.twitter.com/2/users/111/likes/900' => Http::response([], 204)]);

    $reply = PostTargetReply::factory()->create(['platform' => Platform::X, 'remote_reply_id' => '900']);

    $result = xConnector()->unlikeReply(xAccount(), $reply, null, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/2/users/111/likes/900'));
});
