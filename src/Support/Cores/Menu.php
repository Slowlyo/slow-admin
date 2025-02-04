<?php

namespace Slowlyo\OwlAdmin\Support\Cores;

use Illuminate\Support\Arr;
use Slowlyo\OwlAdmin\Admin;
use Slowlyo\OwlAdmin\Services\AdminMenuService;

class Menu
{
    protected array $menus = [];

    public function all()
    {
        $menus = $this->userMenus()
            ->push(...array_map(fn($item) => $this->formatItem($item), $this->menus))
            ->sortBy('custom_order')
            ->values()
            ->toArray();

        return array_merge($this->list2Menu($menus), $this->extra());
    }

    public function userMenus()
    {
        if (!Admin::config('admin.auth.enable')) {
            return collect([]);
        }

        $user = Admin::user();
        if ($user->isAdministrator() || Admin::config('admin.auth.permission') === false) {
            $list = AdminMenuService::make()->query()->orderBy('custom_order')->get();
        } else {
            $user->load('roles.permissions.menus');
            $list = $user->roles
                ->pluck('permissions')
                ->flatten()
                ->pluck('menus')
                ->flatten()
                ->unique('id')
                ->sortBy('custom_order');
        }

        return $list;
    }

    public function list2Menu($list, $parentId = 0, $parentName = ''): array
    {
        $data = [];
        foreach ($list as $key => $item) {
            if ($item['parent_id'] == $parentId) {
                $_component = match ($item['url_type']) {
                    Admin::adminMenuModel()::TYPE_IFRAME => 'iframe',
                    Admin::adminMenuModel()::TYPE_PAGE => 'amis',
                    default => data_get($item, 'component') ?? 'amis'
                };


                $idStr = "[{$item['id']}]";
                $_temp = [
                    'name'       => $parentName ? $parentName . '-' . $idStr : $idStr,
                    'path'       => $item['url'],
                    'component'  => $_component,
                    'is_home'    => $item['is_home'],
                    'iframe_url' => $item['iframe_url'] ?? '',
                    'url_type'   => $item['url_type'] ?? Admin::adminMenuModel()::TYPE_ROUTE,
                    'keep_alive' => $item['keep_alive'] ?? 0,
                    'is_full'    => $item['is_full'] ?? 0,
                    'is_link'    => $item['url_type'] == Admin::adminMenuModel()::TYPE_LINK,
                    'page_sign'  => $item['url_type'] == Admin::adminMenuModel()::TYPE_PAGE ? data_get($item, 'component') : '',
                    'meta'       => [
                        'title'        => $item['title'],
                        'icon'         => $item['icon'] ?? '-',
                        'hide'         => $item['visible'] == 0,
                        'custom_order' => $item['custom_order'],
                    ],
                ];

                $children = $this->list2Menu($list, (int)$item['id'], $_temp['name']);

                if (!empty($children)) {
                    $_temp['component'] = 'amis';
                    $_temp['children']  = $children;
                }

                $data[] = $_temp;
                if (!in_array($_temp['path'], Admin::config('admin.route.without_extra_routes')) && $item['url_type'] != Admin::adminMenuModel()::TYPE_PAGE) {
                    array_push($data, ...$this->generateRoute($_temp));
                }
                unset($list[$key]);
            }
        }
        return $data;
    }

    public function generateRoute($item): array
    {
        $url = $item['path'] ?? '';
        $url = preg_replace('/\?.*/', '', $url);

        if (!$url || array_key_exists('children', $item)) {
            return [];
        }

        $menu = fn($action, $path) => [
            'name'      => $item['name'] . '-' . $action,
            'path'      => $url . $path,
            'component' => $item['url_type'] == 3 ? 'iframe' : (data_get($item, 'component') ?? 'amis'),
            'meta'      => [
                'hide'  => true,
                'icon'  => Arr::get($item, 'meta.icon'),
                'title' => Arr::get($item, 'meta.title') . ' - ' . admin_trans('admin.' . $action),
            ],
        ];

        return [
            $menu('create', '/create'),
            $menu('show', '/:id'),
            $menu('edit', '/:id/edit'),
        ];
    }

    public function add($menus)
    {
        $this->menus = array_merge($this->menus, $menus);

        return $this;
    }

    public function getMenus()
    {
        return  $this->menus;
    }

    public function formatItem($item)
    {
        return array_merge([
            'title'        => '',
            'url'          => '',
            'url_type'     => 1,
            'icon'         => '',
            'parent_id'    => 0,
            'id'           => 999,
            'is_home'      => 0,
            'visible'      => 1,
            'custom_order' => 99,
        ], $item);
    }

    /**
     * 额外菜单
     *
     * @return array|array[]
     */
    public function extra()
    {
        $extraMenus = [];

        if (Admin::config('admin.auth.enable')) {
            $extraMenus[] = [
                'name'      => 'user_setting',
                'path'      => '/user_setting',
                'component' => 'amis',
                'meta'      => [
                    'hide'         => true,
                    'title'        => admin_trans('admin.user_setting'),
                    'icon'         => 'material-symbols:manage-accounts',
                    'singleLayout' => 'basic',
                ],
            ];
        }

        if (Admin::config('admin.show_development_tools')) {
            $user = Admin::user();
            if ($user->isAdministrator() || Admin::config('admin.auth.permission') === false) {
                $extraMenus = array_merge($extraMenus, $this->devToolMenus());
            }
        }

        return $extraMenus;
    }

    /**
     * 开发者工具菜单
     *
     * @return array[]
     */
    public function devToolMenus()
    {
        return [
            [
                'name'      => 'dev_tools',
                'path'      => '/dev_tools',
                'component' => 'amis',
                'meta'      => [
                    'title' => admin_trans('admin.developer'),
                    'icon'  => 'fluent:window-dev-tools-20-regular',
                ],
                'children'  => [
                    [
                        'name'      => 'dev_tools_extensions',
                        'path'      => '/dev_tools/extensions',
                        'component' => 'amis',
                        'meta'      => [
                            'title' => admin_trans('admin.extensions.menu'),
                            'icon'  => 'ion:extension-puzzle-outline',
                        ],
                    ],
                    [
                        'name'      => 'dev_tools_pages',
                        'path'      => '/dev_tools/pages',
                        'component' => 'amis',
                        'meta'      => [
                            'title' => admin_trans('admin.pages.menu'),
                            'icon'  => 'iconoir:multiple-pages',
                        ],
                    ],
                    [
                        'name'      => 'dev_tools_relationships',
                        'path'      => '/dev_tools/relationships',
                        'component' => 'amis',
                        'meta'      => [
                            'title' => admin_trans('admin.relationships.menu'),
                            'icon'  => 'ant-design:node-index-outlined',
                        ],
                    ],
                    [
                        'name'      => 'dev_tools_apis',
                        'path'      => '/dev_tools/apis',
                        'component' => 'amis',
                        'meta'      => [
                            'title' => admin_trans('admin.apis.menu'),
                            'icon'  => 'ant-design:api-outlined',
                        ],
                    ],
                    [
                        'name'      => 'dev_tools_code_generator',
                        'path'      => '/dev_tools/code_generator',
                        'component' => 'amis',
                        'meta'      => [
                            'title' => admin_trans('admin.code_generator'),
                            'icon'  => 'ic:baseline-code',
                        ],
                    ],
                    [
                        'name'      => 'dev_tools_editor',
                        'path'      => '/dev_tools/editor',
                        'component' => 'editor',
                        'meta'      => [
                            'title' => admin_trans('admin.visual_editor'),
                            'icon'  => 'mdi:monitor-edit',
                        ],
                    ],
                ],
            ],
        ];
    }
}
