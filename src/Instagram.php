<?php

namespace Prisma;

use Prisma\Instagram\API;
use Prisma\Instagram\Auth;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;

class Instagram {
	public static function getMedia(int $limit = 5, bool $allowCache = true): ArrayList {
		$cache = Injector::inst()->get(CacheInterface::class . ".Prisma.Instagram");

		if ($allowCache && Controller::curr()->getRequest()->getVar("flush") !== "all" && $cache->has("expiration") && $cache->get("expiration") > time() && $cache->get("media_count") >= $limit) {
			return $cache->get("media")->limit($limit);
		}
		else {
			$posts = new ArrayList([]);

			function decodeMedia(string $id, bool $is_child): ArrayData {
				API::get("https://graph.instagram.com/$id", $is_child ? ["access_token=" . Auth::accessToken(), "fields=media_type,media_url"] : ["access_token=" . Auth::accessToken(), "fields=media_type,media_url,caption,children"], $media);

				return new ArrayData([
					"InstagramID" => $id,
					"Type" => $media->media_type,
					"URL" => $media->media_url,
					"Caption" => property_exists($media, "caption") ? $media->caption : "",
					"Children" => property_exists($media, "children") ? new ArrayList(array_map(function ($child) { return decodeMedia($child->id, true); }, $media->children->data)) : null
				]);
			}

			if (Auth::valid()) {
				API::get("https://graph.instagram.com/me/media", ["access_token=" . Auth::accessToken(), "fields=id", "limit=$limit"], $json);

				if (property_exists($json, "data")) {
					foreach ($json->data as $post) {
						$posts->add(decodeMedia($post->id, false));
					}

					$cache->set("expiration", time() + 43200);
					$cache->set("media", $posts);
					$cache->set("media_count", $limit);
				}
				else {
					user_error("Instagram API Error, " . json_encode($json), E_USER_ERROR);
				}
			}
			else {
				user_error("Invalid Instagram authorization, verify the account is connected.", E_USER_ERROR);
			}

			return $posts;
		}
	}
}