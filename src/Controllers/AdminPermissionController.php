<?php

namespace Slowlyo\OwlAdmin\Controllers;

use Illuminate\Support\Str;
use Slowlyo\OwlAdmin\Admin;
use Illuminate\Support\Facades\DB;
use Slowlyo\OwlAdmin\Renderers\Tag;
use Slowlyo\OwlAdmin\Services\AdminMenuService;
use Slowlyo\OwlAdmin\Services\AdminPermissionService;
use Slowlyo\OwlAdmin\Support\Cores\Menu;

/**
 * @property AdminPermissionService $service
 */
class AdminPermissionController extends AdminController
{
    protected string $serviceName = AdminPermissionService::class;

    public function list()
    {
        $autoBtn = '';
        if (Admin::config('admin.show_auto_generate_permission_button')) {
            $autoBtn = amis()->AjaxAction()
                ->label(admin_trans('admin.admin_permission.auto_generate'))
                ->level('success')
                ->confirmText(admin_trans('admin.admin_permission.auto_generate_confirm'))
                ->api(admin_url('system/_admin_permissions_auto_generate'));
        }

        $crud = $this->baseCRUD()
            ->perPage(999)
            ->loadDataOnce()
            ->filterTogglable(false)
            ->footerToolbar(['statistics'])
            ->headerToolbar([
                $this->createButton(true, 'lg'),
                'bulkActions',
                $autoBtn,
                amis('reload')->set('align', 'right'),
                amis('filter-toggler')->set('align', 'right'),
            ])
            ->columns([
                amis()->TableColumn('id', 'ID')->sortable(),
                amis()->TableColumn('name', admin_trans('admin.admin_permission.name')),
                amis()->TableColumn('slug', admin_trans('admin.admin_permission.slug')),
                amis()->TableColumn('http_method', admin_trans('admin.admin_permission.http_method'))
                    ->type('each')
                    ->items(
                        Tag::make()->label('${item}')->className('my-1')
                    )
                    ->placeholder(Tag::make()->label('ANY')),
                amis()->TableColumn('http_path', admin_trans('admin.admin_permission.http_path'))
                    ->type('each')
                    ->items(
                        Tag::make()->label('${item}')->className('my-1')
                    ),
                $this->rowActions([
                    $this->rowEditButton(true, 'lg'),
                    $this->rowDeleteButton(),
                ]),
            ]);

        return $this->baseList($crud);
    }

    public function form()
    {
        return $this->baseForm()->body([
            amis()->TextControl('name', admin_trans('admin.admin_permission.name'))->required(),
            amis()->TextControl('slug', admin_trans('admin.admin_permission.slug'))->required(),
            amis()->TreeSelectControl('parent_id', admin_trans('admin.parent'))
                ->id('parent_select')
                ->labelField('name')
                ->valueField('id')
                ->options($this->service->getTree()),
            amis()->CheckboxesControl('http_method', admin_trans('admin.admin_permission.http_method'))
                ->options($this->getHttpMethods())
                ->description(admin_trans('admin.admin_permission.http_method_description'))
                ->joinValues(false)
                ->extractValue(),
            amis()->NumberControl('custom_order', admin_trans('admin.order'))
                ->required()
                ->labelRemark(admin_trans('admin.order_asc'))
                ->displayMode('enhance')
                ->min(0)
                ->value(0),
            amis()->ArrayControl('http_path', admin_trans('admin.admin_permission.http_path'))
                ->items(amis()->TextControl()->options($this->getRoutes())->required()),
            amis()->TreeSelectControl('menus', admin_trans('admin.menus'))
                ->searchable()
                ->multiple()
                ->showIcon(false)
                ->options(AdminMenuService::make()->getTree())
                ->labelField('title')
                ->valueField('id')
                ->autoCheckChildren(false)
                ->joinValues(false)
                ->extractValue(),
        ])->onEvent([
            'inited'     => [
                'actions' => [
                    [
                        'actionType'  => 'setValue',
                        'componentId' => 'parent_select',
                        'args'        => [
                            'value' => '${responseData.parent_id || ""}',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function detail()
    {
        return $this->baseDetail()->body([]);
    }

    private function getHttpMethods(): array
    {
        return collect(Admin::adminPermissionModel()::$httpMethods)->map(fn($method) => [
            'value' => $method,
            'label' => $method,
        ])->toArray();
    }

    public function getRoutes(): array
    {
        $prefix = (string)Admin::config('admin.route.prefix');

        $container = collect();
        return collect(app('router')->getRoutes())->map(function ($route) use ($prefix, $container) {
            if (!Str::startsWith($uri = $route->uri(), $prefix) && $prefix && $prefix !== '/') {
                return null;
            }
            if (!Str::contains($uri, '{')) {
                if ($prefix !== '/') {
                    $route = Str::replaceFirst($prefix, '', $uri . '*');
                } else {
                    $route = $uri . '*';
                }

                $route !== '*' && $container->push($route);
            }
            $path = preg_replace('/{.*}+/', '*', $uri);
            $prefix !== '/' && $path = Str::replaceFirst($prefix, '', $path);

            return $path;
        })->merge($container)->filter()->unique()->map(function ($method) {
            return [
                'value' => $method,
                'label' => $method,
            ];
        })->values()->all();
    }

    public function autoGenerate()
    {
        $menus       = Admin::adminMenuModel()::query()->get()->toArray();

        //自定义菜单添加合并
        $customMenus = Admin::menu()->getMenus();
        $menus       = array_merge($menus, $customMenus);

        $slugMap     = Admin::adminPermissionModel()::query()->get(['id', 'slug'])->keyBy('id')->toArray();
        $slugCache   = [];
        $permissions = [];
        $menu_index = 9000;
        foreach ($menus as $menu) {
            $_httpPath =
                $menu['url_type'] == Admin::adminMenuModel()::TYPE_ROUTE ? $this->getHttpPath($menu['url']) : '';

            $menuTitle = $menu['title'];

            // 避免名称重复
            if (in_array($menuTitle, data_get($permissions, '*.name', []))) {
                $menuTitle = sprintf('%s(%s)', $menuTitle, $menu['id']);
            }

            if ($_httpPath) {
                $slug = Str::of(explode('?', $_httpPath)[0])->trim('/')->replace('/', '.')->replace('*', '')->value();
            } else {
                $slug = Str::uuid();
            }

            if (in_array($slug, $slugCache)) {
                $slug = $slug . '.' . $menu['id'];
            }
            $slugCache[] = $slug;

            $permissions[] = [
                'id'           => $menu['id'] ??  ++$menu_index,
                'name'         => $menuTitle,
                'slug'         => data_get($slugMap, $menu['id'] . '.slug') ?: $slug,
                'http_path'    => json_encode($_httpPath ? [$_httpPath] : ''),
                'custom_order' => $menu['custom_order'] ?? 1000,
                'parent_id'    => $menu['parent_id'] ?? 0,
                'created_at'   => $menu['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at'   => $menu['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
        }

        Admin::adminPermissionModel()::query()->truncate();
        Admin::adminPermissionModel()::query()->insert($permissions);

        $permissionClass = Admin::adminPermissionModel();
        $pivotTable      = (new $permissionClass)->menus()->getTable();

        DB::table($pivotTable)->truncate();
        foreach ($permissions as $item) {
            $query = DB::table($pivotTable);
            $query->insert([
                'permission_id' => $item['id'],
                'menu_id'       => $item['id'],
            ]);

            $_id = $item['id'];
            while (data_get($item, 'parent_id', 0) != 0) {
                $query->clone()->insert([
                    'permission_id' => $_id,
                    'menu_id'       => $item['parent_id'],
                ]);

                $item = Admin::adminMenuModel()::query()->find($item['parent_id']);
            }
        }

        return $this->response()->successMessage(
            admin_trans('admin.successfully_message', ['attribute' => admin_trans('admin.admin_permission.auto_generate')])
        );
    }

    protected function getHttpPath($uri)
    {
        $excepts = ['/', '', '-'];
        if (in_array($uri, $excepts)) {
            return '';
        }

        if (!str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }

        return $uri . '*';
    }
}
