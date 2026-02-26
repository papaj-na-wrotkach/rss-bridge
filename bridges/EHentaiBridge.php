<?php

declare(strict_types=1);

class EHentaiBridge extends BridgeAbstract
{
    // common parameters
    private const _SEARCH_PARAMETER = [
        'f_search' => [
            'name' => 'Search Query',
            'type' => 'text',
        ],
    ];
    private const _FILTER_PARAMETERS = [
        'f_sfl' => [
            'name'         => 'Enable Custom Language Filters',
            'type'         => 'checkbox',
            'defaultValue' => 'checked',
        ],
        'f_sfu' => [
            'name'         => 'Enable Custom Uploader Filters',
            'type'         => 'checkbox',
            'defaultValue' => 'checked',
        ],
        'f_sft' => [
            'name'         => 'Enable Custom Tag Filters',
            'type'         => 'checkbox',
            'defaultValue' => 'checked',
        ],
    ];
    private const _CATEGORIES_PARAMETER = [
        'f_cats' => [
            'name'   => 'Gallery Categories',
            'title'  => 'gallery categories to include in the results',
            'type'   => 'multi-list',
            'values' => [
                'Doujinshi'  => 2,
                'Manga'      => 4,
                'Artist CG'  => 8,
                'Game CG'    => 16,
                'Western'    => 512,
                'Non-H'      => 256,
                'Image Set'  => 32,
                'Cosplay'    => 64,
                'Asian Porn' => 128,
                'Misc'       => 1,
            ],
        ],
    ];
    private const _SHARED_PARAMETERS = [
        ...self::_SEARCH_PARAMETER,
        ...self::_CATEGORIES_PARAMETER,
        ...self::_FILTER_PARAMETERS,
        'f_sh' => [
            'name' => 'Browse Expunged',
            'type' => 'checkbox',
        ],
        'f_sto' => [
            'name' => 'Require Torrent',
            'type' => 'checkbox',
        ],
        'f_spf' => [
            'name'  => 'Minimum Pages',
            'type'  => 'number',
        ],
        'f_spt' => [
            'name'  => 'Maximum Pages',
            'type'  => 'number',
        ],
        'f_srdd' => [
            'name'   => 'Minimum Rating',
            'type'   => 'list',
            'values' => [
                'Any Rating' => '0',
                '2 Stars'    => '2',
                '3 Stars'    => '3',
                '4 Stars'    => '4',
                '5 Stars'    => '5',
            ],
        ],
    ];

    const NAME = 'E-Hentai';
    const URI  = 'https://e-hentai.org';
    const PARAMETERS = [
        'global' => [
            'thumbnails' => [
                'name'         => 'Include Thumbnails',
                'title'        => 'include thumbnail for every entry',
                'type'         => 'checkbox',
                'defaultValue' => 'checked',
            ],
            'author_links' => [
                'name'         => 'Include Authors\' Links',
                'title'        => 'include link to author profile for every entry using HTML <a> tag',
                'type'         => 'checkbox',
                'defaultValue' => 'checked',
            ],
            'torrents' => [
                'name'  => 'Include Torrents',
                'title' => 'include torrent links as attachments in entries that have torrents (WARNING makes additional request for every entry)',
                'type'  => 'checkbox',
            ],
        ],
        'search'  => self::_SHARED_PARAMETERS,
        'popular' => self::_CATEGORIES_PARAMETER,
        'watched' => self::_SHARED_PARAMETERS,
        'favorites' => [
            ...self::_SEARCH_PARAMETER,
            'favcat' => [
                'name'    => 'Favorite Category',
                'title'   => 'the favorite category number or "all"',
                'type'    => 'text',
                'pattern' => '\d|all',
            ],
            'inline_set' => [
                'name'   => 'Order',
                'type'   => 'list',
                'values' => [
                    'Published Time' => 'fs_p',
                    'Favorited Time' => 'fs_f',
                ],
            ],
        ],
    ];
    const CONFIGURATION = [
        'exhentai'      => [
            'defaultValue' => false,
        ],
        // cookies:
        //   selected profile
        'sp'             => [ 'defaultValue' => null, ],
        //   auth cookies
        'igneous'        => [ 'defaultValue' => null, ],
        'ipb_member_id'  => [ 'defaultValue' => null, ],
        'ipb_pass_hash'  => [ 'defaultValue' => null, ],
        'ipb_session_id' => [ 'defaultValue' => null, ],
        'sk'             => [ 'defaultValue' => null, ],
    ];

    public $cookies = [
        'sl' => 'dm_2',
    ];
    public $headers = [];
    public $queryParams = [];

    // Gather the inputs from the user
    public function collectData()
    {
        // cookies
        if ($this->getOption('exhentai')) {
            $allowedCookies = [ 'igneous', 'ipb_member_id', 'ipb_pass_hash', 'sk', ];
        } else {
            $allowedCookies = [ 'ipb_member_id', 'ipb_pass_hash', 'ipb_session_id', 'sk' ];
        }

        $this->cookies = array_reduce($allowedCookies, function ($agg, $key) {
            $val = $this->getOption($key);
            if (!is_null($val)) {
                $agg[$key] = $val;
            }
            return $agg;
        }, $this->cookies);

        if ($this->getOption('exhentai')) {
            $cookies['yay'] = 'louder';
        }

        // inputs
        foreach (array_keys(self::PARAMETERS[$this->queriedContext]) as $param) {
            $value = $this->getInput($param);
            $value = match ($param) {
                // calculate using category value as a bitmask
                'f_cats' => is_null($value) ? null : 1023 & ~array_reduce($value, fn($agg, $val) => $agg | (int)$val, 0),
                // do not pass empty string
                'f_search' => $value === '' ? null : $value,
                // do not pass when other than 0-9 ('all' is default)
                'favcat' => (is_numeric($value) && $value >= 0 && $value <= 9) ? $value : null,
                // do not pass when enabled, pass 'on' when disabled
                'f_sfl', 'f_sfu', 'f_sft' => empty($value) ? null : 'on',
                // normal logic - do not pass if falsy/empty
                default => $value ?: null,
            };

            if (!is_null($value)) {
                $this->queryParams[$param] = $value;
            }
        }

        $this->scrape();
    }

    private function scrape()
    {
        $headers = [
            ...$this->headers,
            'Cookie: ' . implode('; ', array_map(fn($k, $v) => $k . '=' . $v, array_keys($this->cookies), array_values($this->cookies))),
        ];

        $dom = getSimpleHTMLDOM($this->getURI(), $headers);
        $galleries = $dom->find('table[class^="itg gl"] > tr');
        // filter ads
        $galleries = array_filter($galleries, fn($el) => !$el->first_child()->hasClass('itd'));

        $this->items = array_map(function ($el) use($headers) {
            $thumb = $el->find('td.gl1e > div > a', 0);
            $meta = $el->find('td.gl2e > div > div.gl3e', 0);

            $item['title'] = $el->find('.glink', 0)->innertext;
            $item['uri'] = $thumb->href;
            // author might be null if gallery is disowned
            $item['author'] = $meta->find('.ir + div > a', 0)?->__get($this->getInput('author_links') ? 'outertext' : 'innertext');
            $item['timestamp'] = strtotime($meta->find('div[id^="posted"]', 0)->innertext);
            $item['categories'] = [
                // category as tag
                'category:' . strtolower($meta->find('div.cn', 0)->innertext),
                // other tags
                ...array_map(fn($tag) => $tag->title, $el->find('[class^="gt"]')),
            ];
            // the path from the URL without /g/ and leading slash
            $item['uid'] = substr(parse_url($item['uri'], PHP_URL_PATH), 4, -1);
            if ($this->getInput('thumbnails')) {
                $thumbUrl = $thumb->first_child()->src;
                // replace the host so cookies are not required on the client-side
                $thumbUrl = str_replace('s.exhentai.org', 'ehgt.org', $thumbUrl);

                $item['content'] = '<img src="' . $thumbUrl . '" alt="thumbnail" referrerpolicy="no-referrer">';
            }
            if ($this->getInput('torrents')) {
                $torrentPageUrl = $meta->find('.gldown > a', 0)?->href;
                if (!is_null($torrentPageUrl)) {
                    // decode special characters that might be present in the URL
                    $torrentPageUrl = htmlspecialchars_decode($torrentPageUrl, ENT_QUOTES | ENT_SUBSTITUTE);

                    $item['enclosures'] = array_map(fn($a) => $a->href, getSimpleHTMLDOM($torrentPageUrl, $headers)->find('form table a'));
                }
            }

            return $item;
        }, $galleries);
    }

    public function getURI()
    {
        return 'https://' . strtolower($this->getSiteName()) . '.org' . match ($this->queriedContext) {
            'popular'   => '/popular',
            'watched'   => '/watched',
            'favorites' => '/favorites.php',
            default     => '/',
        } . ($this->queryParams ? ('?' . http_build_query($this->queryParams)) : '');
    }

    private function getSiteName()
    {
        return 'E' . ($this->getOption('exhentai') ? 'x' : '-') . 'Hentai';
    }

    public function getName()
    {
        return match ($this->queriedContext) {
            'popular'   => 'Currently Popular Recent Galleries — ',
            'watched'   => 'Watched Tag Galleries — ',
            'favorites' => 'Favorites — ',
            'search'    => 'Recent Galleries — ',
            default     => ''
        } . $this->getSiteName();
    }

    public function detectParameters($url)
    {
        $urlRegex = '~^(?:https?://)?(?:(?:www\.)?e[-x]hentai\.org)(?<path>/(?<context>popular|watched|favorites|)(?:.php)?)(?:\?(?<query>[^#]*))?.*$~';

        if (preg_match($urlRegex, $url, $urlMatches) <= 0) {
            return null;
        }

        $context = $urlMatches['context'];
        if ($context === '') {
            $context = 'search';
        }

        $urlMatches['query'] && parse_str($urlMatches['query'], $query);

        $params = [];
        $params['context'] = $context;

        $contexts = $this->getParameters();
        $allowedParams = array_keys($contexts[$context]);

        foreach (array_diff($allowedParams, [ 'f_cats', 'f_sfl', 'f_sfu', 'f_sft' ]) as $key) {
            if (isset($query[$key])) {
                $params[$key] = $query[$key];
            }
        }

        if (array_key_exists('f_cats', $contexts[$context]) && isset($query['f_cats'])) {
            $params['f_cats'] = array_reduce(
                array_keys(self::_CATEGORIES_PARAMETER['f_cats']['values']),
                function ($agg, $key) use ($query) {
                    if (!(self::_CATEGORIES_PARAMETER['f_cats']['values'][$key] & (int)$query['f_cats'])) {
                        $agg[] = $key;
                    }
                    return $agg;
                },
                [],
            );
        }

        foreach (array_intersect([ 'f_sfl', 'f_sfu', 'f_sft' ], $allowedParams) as $key) {
            if (!isset($query[$key])) {
                $params[$key] = 'on';
            }
        }

        $globalParams = array_filter($contexts['global'], fn($val) => isset($val['defaultValue']));
        foreach ($globalParams as $key => &$val) {
            $val = $val['defaultValue'] === 'checked' ? 'on' : $val['defaultValue'];
        }
        $params += $globalParams;

        return $params;
    }
}
