<?php

App::uses('VedAppController', 'Ved.Controller');


class PrintersController extends VedAppController
{

    var $uses = array(
        'Printer',
        'Area',
        'Region',
        'Province',
        'User',
    );

    public $components = array(
        'ProcessWrapperApi',
    );


    public static $_HAND_OVER_TYPES = array(
        'lend'      => 'Cho mượn',
        'sell'      => 'Bán',
        'give_away' => 'Tặng',
    );

    public static $_UPDATE_ACTIONS = array(
        'not_working' => 'Hư hỏng',
        'return'      => 'Không sử dụng',
        'sell'        => 'Bán',
        'give_away'   => 'Tặng',
        'lost'        => "Mất",
    );

    public static $_CHECK_RESULTS = array(
        'fixed'      => 'Đã sửa',
        'normal'     => 'Bình thường',
        'cannot_fix' => 'Không sửa được',
    );

    public static $_STATES = array(
        'new'         => 'Mới nguyên',
        'in_use'      => 'Đang sử dụng',
        'not_working' => 'Hư hỏng',
        'used'        => 'Đã sử dụng',
        'lost'        => 'Bị mất',
    );

    public static $_STATUSES = array(
        'region'      => 'Đang ở kho Miền',
        'province'    => 'Đang ở kho Tỉnh',
        'to_region'   => 'Đang chuyển về Miền',
        'to_province' => 'Đang chuyển về Tỉnh',
        'nvrsd'       => 'NV RSD đang giữ',
        'customer'    => 'Đã giao Khách hàng',
        'clearance'   => 'Đã thanh lý',
        'lost'        => 'Bị mất',
    );

    public static $_OWNERS = array(
        'ved'      => 'Vietnam eSports',
        'customer' => 'Khách hàng',
    );

    public static $_TASK_STATUSES = array(
        'assigned'   => 'Được phân công',
        'processing' => 'Đang xử lý',
        'unassigned' => 'Chờ phân công',
        'related'    => 'Liên quan',
    );


    public function index()
    {
        $canViewWithoutAreaRestriction = $this->Acl->check(array('User' => $this->Auth->user()),
                                                           'controllers/Printers/can_view_without_area_restriction');

        $canViewNoProvince = $canViewWithoutAreaRestriction ? TRUE
            : $this->Acl->check(array('User' => $this->Auth->user()),
                                'controllers/Printers/can_view_no_province');

        $canViewWithoutTaskRestriction = $this->Acl->check(array('User' => $this->Auth->user()),
                                                           'controllers/Printers/can_view_without_task_restriction');

//        $exporting = isset($this->request->query['export']) ? $this->request->query['export'] : false;

        $this->Prg->commonProcess('Printer', array(
            'paramType' => 'querystring',
        ));

        $params = array(
            'with_task' => 1,
        );

        $mapping = array(
            'page'      => 'page',
            'sort'      => 'orderby',
            'direction' => 'order',
            'code'      => 'code',
            'region'    => 'region',
            'province'  => 'province',
            'tas_uid'   => 'tas_uids',
            'assigned'  => 'assigned',
            'limit'     => 'perpage',
        );
        foreach (array_keys($mapping) as $p) {
            if (!empty($this->request->query[$p])) {
                $params[$mapping[$p]] = $this->request->query[$p];
            }
        }
        if (!empty($params['tas_uids']) && is_string('tas_uids')) {
            $tasUids = explode(',', $params['tas_uids']);
            if (count($tasUids) > 1) {
                $params['tas_uids'] = $tasUids;
            }
        }
        $this->set('searching', $this->request->query);

        if (!$canViewWithoutAreaRestriction) {
            $this->loadModel('Area');
            $managed_units = $this->Area->get_own();
            $parsedConditions['OR'] = array(
                array('SaleCafe.province_id' => $managed_units['provinces']),
                array('SaleCafe.district_id' => $managed_units['districts']),
            );
            $this->set('managed_units', $managed_units);

            // remove searching province if not in managed provinces
            if (!empty($params['province'])) {
                if (!in_array($params['province'], $managed_units['provinces'])) {
                    $params['province'] = NULL;
                }
            }

            // restrict to managed provinces
            if (empty($params['province'])) {
                $params['province'] = $managed_units['provinces'];

                if ($canViewNoProvince) {
                    $params['province'][] = '_NULL_';
                }
            }
        }

        if (!$canViewWithoutTaskRestriction) {
            $params['wf_user_email'] = $this->Auth->user('email');
            $rs = $this->ProcessWrapperApi->get('printers/task_definitions');

//            $canViewXacNhanBanGiao = $this->Acl->check(array('User' => $this->Auth->user()),
//                                                       'controllers/Printers/can_view_xac_nhan_ban_giao');
//            if ($canViewXacNhanBanGiao) {
//                $params['inc_tasks[]'] = $this->ProcessWrapperApi->getTaskUid('xac_nhan_ban_giao');
//            }
        }
        else {
            $rs = $this->ProcessWrapperApi->get('printers/task_definitions', array(), TRUE);
        }
        $taskDefinitions = (array)$rs->response;
//        if (@$canViewXacNhanBanGiao) {
//            $taskDefinitions[$this->ProcessWrapperApi->getTaskUid('xac_nhan_ban_giao')] = __('xac_nhan_ban_giao');
//        }
        $this->set("tasks", $taskDefinitions);

        // assigned can be 0 or 1
        if (!empty($params['assigned'])) {
            $params['wf_user_email'] = $this->Auth->user('email');
            if ($params['assigned'] == 'related') {
                unset($params['assigned']);
            }
            else {
                $params['assigned'] = $params['assigned'] == 'assigned';
            }
        }

//        if ($exporting) {
//            $sort = isset($this->passedArgs['sort']) ? $this->passedArgs['sort'] : null;
//            $direction = isset($this->passedArgs['direction']) ? $this->passedArgs['direction'] : null;
//        }

        $rs = $this->ProcessWrapperApi->get('printers', $params, TRUE);
        $paging = $rs->response;

        $count = $paging->total;
        $limit = $paging->per_page;
        $pageCount = intval(ceil($count / $limit));
        $page = $paging->current_page;
        $page = max(min($page, $pageCount), 1);

        $this->request['paging'] = array_merge(
            (array)$this->request['paging'],
            array(
                'Printer' => array(
                    'page'      => $page,
                    'current'   => $page,
                    'count'     => $count,
                    'prevPage'  => ($page > 1),
                    'nextPage'  => ($count > ($page * $limit)),
                    'pageCount' => $pageCount,
                    'order'     => trim(@$params['orderby'] . ' ' . @$params['order']),
                    'limit'     => $limit,
                    'options'   => array(),   // Hash::diff($options, $defaults),
                    'paramType' => 'querystring',       // $options['paramType'],
                )
            )
        );

        $isMobile = $this->Session->read("mobileApp");
        if (!$isMobile) {
            $this->set('printers', $paging->data);

            $this->set("canViewWithoutTaskRestriction", $canViewWithoutTaskRestriction);

            $assignedOptions = self::$_TASK_STATUSES;
            unset($assignedOptions['processing']);
            $this->set('assignedOptions', $assignedOptions);

            $this->set('canViewNoProvince', $canViewNoProvince);
        }
        else {
            return new CakeResponse(
                array(
                    'type' => 'json',
                    'body' => json_encode(
                        array(
                            'errorCode' => 0,
                            'error'     => NULL,
                            'response'  => array(
                                'data'   => $paging->data,
                                'paging' => $this->request['paging'],
                            ),
                        ))
                ));
        }
    }


    public function can_view_no_province()
    {
        // JUST DEFINE TO CREATE PERMISSION
    }

    public function can_view_without_area_restriction()
    {
        // JUST DEFINE TO CREATE PERMISSION
    }

    public function can_view_without_task_restriction()
    {
        // JUST DEFINE TO CREATE PERMISSION
    }

//    public function can_view_xac_nhan_ban_giao()
//    {
//        // JUST DEFINE TO CREATE PERMISSION
//    }


    public function list_xac_nhan_ban_giao()
    {
        $this->Prg->commonProcess('Printer', array(
            'paramType' => 'querystring',
        ));

        $mapping = array(
            'page'      => 'page',
            'sort'      => 'orderby',
            'direction' => 'order',
            'limit'     => 'perpage',
        );
        foreach (array_keys($mapping) as $p) {
            if (!empty($this->request->query[$p])) {
                $params[$mapping[$p]] = $this->request->query[$p];
            }
        }
        $this->set('searching', $this->request->query);

        $params['tas_uids[]'] = $this->ProcessWrapperApi->getTaskUid('xac_nhan_ban_giao');

        $rs = $this->ProcessWrapperApi->get('printers/nvrsd_related', $params);
        $paging = $rs->response;

        $count = $paging->total;
        $limit = $paging->per_page;
        $pageCount = intval(ceil($count / $limit));
        $page = $paging->current_page;
        $page = max(min($page, $pageCount), 1);

        $this->request['paging'] = array_merge(
            (array)$this->request['paging'],
            array(
                'Printer' => array(
                    'page'      => $page,
                    'current'   => $page,
                    'count'     => $count,
                    'prevPage'  => ($page > 1),
                    'nextPage'  => ($count > ($page * $limit)),
                    'pageCount' => $pageCount,
                    'order'     => trim(@$params['orderby'] . ' ' . @$params['order']),
                    'limit'     => $limit,
                    'options'   => array(),   // Hash::diff($options, $defaults),
                    'paramType' => 'querystring',       // $options['paramType'],
                )
            )
        );

        return new CakeResponse(
            array(
                'type' => 'json',
                'body' => json_encode(
                    array(
                        'errorCode' => 0,
                        'error'     => NULL,
                        'response'  => array(
                            'data'   => $paging->data,
                            'paging' => $this->request['paging'],
                        ),
                    ))
            ));
    }


    public function add()
    {
        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            return $this->_add($params);
        }
    }


    private function _add(array $params, $rt = NULL)
    {
        if (empty($rt)) {
            $rt = array('action' => 'index');
        }

        $rs = $this->ProcessWrapperApi->post('printers', $params);
        if ($rs->code == 0) {
            $this->Session->setFlash(__('The printer has been saved.'), 'success');

            return $this->redirect($rt);
        }
        else {
            $this->Session->setFlash(__('The printer could not be saved. Please, try again.'), 'error');

            if ($rs->code == 1002) {
                $this->Printer->validationErrors = (array)$rs->payload->errors;
            }
        }
    }


    public function import()
    {
        if ($this->request->is('post')) {

            set_time_limit(0);

            $importFile = $this->request->data['Printer']['import_file'];

            if ((isset($importFile['error']) && $importFile['error'] == 0) ||
                (!empty($importFile['tmp_name']) && $importFile['tmp_name'] != 'none')
            ) {
                if (!is_uploaded_file($importFile['tmp_name'])) {
                    $this->Session->setFlash("Invalid upload file.", "error");

                    return;
                }

                $ext = pathinfo($importFile['name'], PATHINFO_EXTENSION);
                if (!in_array($ext, array('xls', 'xlsx'))) {
                    $this->Session->setFlash("Invalid upload filetype.", "error");

                    return;
                }

                $params = array('__FILES__' => array('importing_printers' => realpath($importFile['tmp_name'])));
                $rs = $this->ProcessWrapperApi->post('printers/import', $params);

                if ($rs->code != 0) {
                    $this->Session->setFlash("Importing printer from file failed.", "error");
                    $this->Printer->validationErrors = array('import_file' => (array)$rs->payload->errors);
                }
                else {
                    $this->Session->setFlash("Importing printer from file successful.", "success");
                }
            }
            else {
                $this->Session->setFlash("Invalid upload file.", "error");
            }
        }
    }


    public function view($code = NULL)
    {
        $canViewHistory = $this->Acl->check(
            array('User' => $this->Auth->user()),
            'controllers/Printers/can_view_history');

        $canViewModifications = $this->Acl->check(
            array('User' => $this->Auth->user()),
            'controllers/Printers/can_view_modifications');

        $canViewHandovers = $this->Acl->check(
            array('User' => $this->Auth->user()),
            'controllers/Printers/can_view_handovers');

        $code = str_replace('/', '.', $code);

        $printer = $this->getPrinter($code);

        $rs = $this->ProcessWrapperApi->get("printers/{$code}/current_task");
        $curTask = @$rs->response;

        if ($canViewHistory) {
            $rs = $this->ProcessWrapperApi->get("printers/{$code}/history");
            $history = $rs->response->data->flow;
        }

        if ($canViewModifications) {
            $rs = $this->ProcessWrapperApi->get("printers/{$code}/modifications");
            $modifications = $rs->response;
        }

        if ($canViewHandovers) {
            $rs = $this->ProcessWrapperApi->get("printers/{$code}/handovers");
            $handovers = $rs->response;
        }

        $isMobile = $this->Session->read("mobileApp");
        if (!$isMobile) {
            $this->set('printer', $printer);
            $this->set('curTask', $curTask);
            if ($canViewHistory) {
                $this->set("history", $history);
            }
            if ($canViewModifications) {
                $this->set("modifications", $modifications);
            }
            if ($canViewHandovers) {
                $this->set("handovers", $handovers);
            }
        }
        else {
            $rs = array(
                'printer' => $printer,
                'task'    => $curTask,
            );
            if ($canViewHistory) {
                $rs['history'] = $history;
            }
            if ($canViewModifications) {
                $rs['modifications'] = $modifications;
            }
            if ($canViewHandovers) {
                $rs["handovers"] = $handovers;
            }

            return new CakeResponse(
                array(
                    'type' => 'json',
                    'body' => json_encode(
                        array(
                            'errorCode' => 0,
                            'error'     => NULL,
                            'response'  => $rs,
                        ))
                ));
        }
    }


    public function can_view_history()
    {
        // JUST DEFINE TO CREATE PERMISSION
    }


    public function can_view_modifications()
    {
        // JUST DEFINE TO CREATE PERMISSION
    }


    public function can_view_handovers()
    {
        // JUST DEFINE TO CREATE PERMISSION
    }


    public function edit($code = NULL)
    {
        $printer = $this->getPrinter($code);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // user must also has permission as process supervisor on workflow system
            //$rs = $this->ProcessWrapperApi->put("printers/{$code}", $params, TRUE);
            $code = str_replace('/', '.', $code);
            $rs = $this->ProcessWrapperApi->put("printers/{$code}", $params);
            if ($rs->code == 0) {
                $this->Session->setFlash(__('The printer has been saved.'), 'success');

                return $this->redirect(array('action' => 'view', $code));
            }
            else {
                $this->Session->setFlash(__('The printer could not be saved. Please, try again.'), 'error');

                if ($rs->code == 1002) {
                    $this->Printer->validationErrors = (array)$rs->payload->errors;
                }
            }
        }

        $this->set("printer", $printer);

        $this->loadModel('Area');
        $managed_units = $this->Area->get_own();
        $parsedConditions['OR'] = array(
            array('SaleCafe.province_id' => $managed_units['provinces']),
            array('SaleCafe.district_id' => $managed_units['districts']),
        );
        $provinces = $this->Province->getList($managed_units['provinces'], $managed_units['districts']);
        $this->set("provinces", $provinces);
    }


    public function task($code, $tas_uid)
    {
        $task = $this->ProcessWrapperApi->getTaskFromUid($tas_uid);
        if ($task) {
            return $this->{$task}($code);
        }
    }


    public function nhap_thong_tin()
    {
        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];
            $params['task'] = 'nhap-thong-tin';

            return $this->_add($params, array('action' => 'view', $params['code']));
        }

        $this->loadModel('Area');
        $managed_units = $this->Area->get_own();
        $parsedConditions['OR'] = array(
            array('SaleCafe.province_id' => $managed_units['provinces']),
            array('SaleCafe.district_id' => $managed_units['districts']),
        );
        $provinces = $this->Province->getList($managed_units['provinces'], $managed_units['districts']);
        $this->set("provinces", $provinces);
    }


    public function xuat_di_tinh($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        $this->loadModel('Area');
        $managed_units = $this->Area->get_own();
        $parsedConditions['OR'] = array(
            array('SaleCafe.province_id' => $managed_units['provinces']),
            array('SaleCafe.district_id' => $managed_units['districts']),
        );

        // cac tinh thuoc region cua may in & thuoc vung quan ly cua user
        $provinces = $this->Province->getList($managed_units['provinces'], $managed_units['districts'], $printer->REGION_ID);

        // TODO: validate

        if ($this->request->is('post')) {
            $rs = $this->claimAndProcessTask($printer);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);
        $this->set("provinces", $provinces);

        return $this->render('xuat_di_tinh');
    }


    public function nhap_kho_tinh($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $rs = $this->claimAndProcessTask($printer);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('nhap_kho_tinh');
    }


    public function xuat_cho_nhan_vien($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        $inProvinceUserIds = $this->Area->getCanManageProvince($printer->PROVINCE_ID, $identifier = 'id');
        $users = $this->User->find('list', array(
            'conditions' => array(
                'id'          => $inProvinceUserIds,
                'active'      => 1,
                'dealer_id >' => 0,
            ),
            'order'      => array(
                'fullname'
            ),
        ));

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];
            $nvrsd_id = $params['nvrsd_id'];
            unset($params['nvrsd_id']);
            $nvrsd = $this->User->find('all', array(
                'fields'     => array('email'),
                'conditions' => array('id' => $nvrsd_id),
                'recursive'  => -1,
                'limit'      => 1,
            ));
            $nvrsd_email = $nvrsd[0]['User']['email'];
            $rs = $this->ProcessWrapperApi->get("workflow_users/search", array('email' => $nvrsd_email));
            $wfuser = $rs->response;
            if (empty($wfuser)) {
                $this->Session->setFlash("Cannot process current task of this printer", "error");
                $this->Printer->validationErrors = array('nvrsd_id' => array("The selected user has no account on workflow system"));
                $rs = FALSE;
            }

            if ($rs) {
                $params['NVRSD_UID'] = $wfuser->pm_uid;

                $rs = $this->claimAndProcessTask($printer, $params);
                if ($rs) {
                    $this->redirect(array(
                                        'action' => 'view',
                                        $code,
                                    ));
                }
                else {
                    if (isset($this->Printer->validationErrors['NVRSD_UID'])) {
                        $this->Printer->validationErrors['nvrsd_id'] = $this->Printer->validationErrors['NVRSD_UID'];
                        unset($this->Printer->validationErrors['NVRSD_UID']);
                    }
                }
            }
        }

        $this->set('printer', $printer);
        $this->set('users', $users);

        return $this->render('xuat_cho_nhan_vien');
    }


    public function nhap_kho_nhan_vien($code = NULL)
    {
        $isMobile = $this->Session->read("mobileApp");

        if ($code == NULL) {
            $code = $this->request->is('post') ? $this->request->data['code'] : $this->request->query['code'];
        }

        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $rs = $this->claimAndProcessTask($printer);
            if ($rs) {
                if (!$isMobile) {
                    $this->redirect(array(
                                        'action' => 'view',
                                        $code,
                                    ));
                }
                else {
                    return new CakeResponse(
                        array(
                            'type' => 'json',
                            'body' => json_encode(
                                array(
                                    'errorCode' => 0,
                                    'error'     => NULL,
                                ))
                        ));
                }
            }
        }

        if ($isMobile) {
            throw new NotFoundException();
        }

        $this->set('printer', $printer);

        return $this->render('nhap_kho_nhan_vien');
    }


    public function ban_giao_khach_hang($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);
        $this->set('handOverTypes', self::$_HAND_OVER_TYPES);

        return $this->render('ban_giao_khach_hang');
    }


    public function xac_nhan_ban_giao($code = NULL)
    {
        $printer = $this->getPrinter($code);
        $cyberpayId = $printer->CAFE_ID;

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $rs = $this->claimAndProcessTask($printer);
            if ($rs) {
                $this->touchSaleCafe($cyberpayId);

                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('xac_nhan_ban_giao');
    }


    public function cap_nhat_trang_thai($code = NULL)
    {
        $isMobile = $this->Session->read("mobileApp");

        if ($code == NULL) {
            $code = $this->request->is('post') ? $this->request->data['code'] : $this->request->query['code'];
        }

        $printer = $this->getPrinter($code);
        $cyberpayId = $printer->CAFE_ID;

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {

            $params = $isMobile ? $this->request->data : $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->touchSaleCafe($cyberpayId);

                if (!$isMobile) {
                    $this->redirect(array(
                                        'action' => 'view',
                                        $code,
                                    ));
                }
                else {
                    return new CakeResponse(
                        array(
                            'type' => 'json',
                            'body' => json_encode(
                                array(
                                    'errorCode' => 0,
                                    'error'     => NULL,
                                ))
                        ));
                }
            }
            else {
                return new CakeResponse(
                    array(
                        'type' => 'json',
                        'body' => json_encode(
                            array(
                                'errorCode' => 101,
                                'error'     => NULL,
                            ))
                    ));
            }
        }

        if ($isMobile) {
            throw new NotFoundException();
        }

        $this->set('printer', $printer);
        $this->set('updateActions', self::$_UPDATE_ACTIONS);

        return $this->render('cap_nhat_trang_thai');
    }


    public function thu_hoi_tinh($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('thu_hoi_tinh');
    }


    public function chuyen_cbs_tinh($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('chuyen_cbs_tinh');
    }


    public function ket_qua_kiem_tra_tinh($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);
        $this->set('checkResults', self::$_CHECK_RESULTS);

        return $this->render('ket_qua_kiem_tra_tinh');
    }


    public function gui_tra_mien($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('gui_tra_mien');
    }


    public function thu_hoi_mien($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('thu_hoi_mien');
    }


    public function chuyen_cbs_mien($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('chuyen_cbs_mien');
    }


    public function ket_qua_kiem_tra_mien($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);
        $this->set('checkResults', self::$_CHECK_RESULTS);

        return $this->render('ket_qua_kiem_tra_mien');
    }


    public function nhap_kho_mien($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $rs = $this->claimAndProcessTask($printer);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('nhap_kho_mien');
    }


    public function thanh_ly($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('thanh_ly');
    }


    public function tinh_ghi_nhan_mat($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('tinh_ghi_nhan_mat');
    }


    public function mien_ghi_nhan_mat($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('mien_ghi_nhan_mat');
    }


    public function giam_doc_rsd_duyet_mat($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('giam_doc_rsd_duyet_mat');
    }


    public function xac_nhan_mat($code = NULL)
    {
        $printer = $this->getPrinter($code);

        $this->checkCurrentTask($printer);

        if ($this->request->is('post')) {
            $params = $this->request->data['Printer'];

            // TODO: validate

            $rs = $this->claimAndProcessTask($printer, $params);
            if ($rs) {
                $this->redirect(array(
                                    'action' => 'view',
                                    $code,
                                ));
            }
        }

        $this->set('printer', $printer);

        return $this->render('xac_nhan_mat');
    }


    public function tasks()
    {
        $this->Prg->commonProcess('Printer', array(
            'paramType' => 'querystring',
        ));

        $params = array();
        $mapping = array(
            'page'      => 'page',
            'sort'      => 'orderby',
            'direction' => 'order',
            //            'code'      => 'code',
            //            'region'    => 'region',
            //            'province'  => 'province',
        );
        foreach (array_keys($mapping) as $p) {
            if (!empty($this->request->query[$p])) {
                $params[$mapping[$p]] = $this->request->query[$p];
            }
        }

        /*
        $rs = $this->ProcessWrapperApi->get("printers/tasks", $params);
        $paging = $rs->response;

        $printers = array();
        foreach (array('assigned', 'processing', 'unassigned') as $type) {
            if (!empty($paging->{$type})) {
                foreach ($paging->{$type} as $idx => $task) {
                    $printer = $task->asset;
                    $printer->task = new stdClass();
                    $printer->task->title = $task->app_tas_title;
                    $printer->task->status = $type;
                    $printer->task->uid = $task->tas_uid;
                    $printer->task->key = $this->ProcessWrapperApi->getTaskFromUid($task->tas_uid);
                    $printers[] = $printer;
                }
            }
        }
        $this->set('printers', $printers);
        */

        $rs = $this->ProcessWrapperApi->get("printers/task_list", $params);
        $paging = $rs->response;

        $printers = array();
        foreach ($paging->data as $entry) {
            $printer = $entry;
            $printer->task->key = $this->ProcessWrapperApi->getTaskFromUid($entry->task->uid);

            unset($printer->task->app_uid);
            unset($printer->task->pro_uid);
            unset($printer->task->del_index);

            $printers[] = $printer;
        }

        $this->set('searching', $params);

        $count = $paging->total;
        $limit = $paging->per_page;
        $pageCount = intval(ceil($count / $limit));
        $page = $paging->current_page;
        $page = max(min($page, $pageCount), 1);

        $this->request['paging'] = array_merge(
            (array)$this->request['paging'],
            array(
                'Printer' => array(
                    'page'      => $page,
                    'current'   => $page,
                    'count'     => $count,
                    'prevPage'  => ($page > 1),
                    'nextPage'  => ($count > ($page * $limit)),
                    'pageCount' => $pageCount,
                    'order'     => trim(@$params['orderby'] . ' ' . @$params['order']),
                    'limit'     => $limit,
                    'options'   => array(),   // Hash::diff($options, $defaults),
                    'paramType' => 'querystring',       // $options['paramType'],
                )
            )
        );

        $isMobile = $this->Session->read("mobileApp");
        if (!$isMobile) {
            $this->set('printers', $printers);
        }
        else {
            return new CakeResponse(
                array(
                    'type' => 'json',
                    'body' => json_encode(
                        array(
                            'errorCode' => 0,
                            'error'     => NULL,
                            'response'  => $printers,
                        ))
                ));
        }
    }


    private function getPrinter($code = NULL)
    {
        if (empty($code)) {
            $code = $this->request->data['Printer']['code'];
        }

        if (empty($code)) {
            throw new NotFoundException();
        }

        $code = str_replace('/', '.', $code);

        $rs = $this->ProcessWrapperApi->get("printers/{$code}");
        $printer = $rs->response;

        if (empty($printer)) {
            throw new NotFoundException();
        }

        return $printer;
    }


    private function claimAndProcessTask($printer, $params = NULL)
    {
        if ($params === NULL) {
            $params = !empty($this->request->data['Printer']) ? $this->request->data['Printer'] : array();
        }

        $rs = $this->ProcessWrapperApi->claimAndProcessTask($printer, $params);

        if ($rs === 0) {
            $this->Session->setFlash(__('Your request has been processed successfully.'), 'Success');

            return TRUE;
        }
        else if ($rs === 1) {
            $this->Session->setFlash(__('Cannot claim to process current task of this printer.'), 'error');

            return FALSE;
        }
        else {
            $this->Session->setFlash(__('Cannot process current task of this printer.'), 'error');
            if ($rs->code == 1002) {
                $this->Printer->validationErrors = (array)$rs->payload->errors;
            }

            return FALSE;
        }
    }


    public function beforeFilter()
    {
        parent::beforeFilter();

        if ($this->action == 'index') {
            $this->Security->validatePost = FALSE;
            $this->Security->csrfCheck = FALSE;
        }

        $api_methods = array(
            'update_actions',
            'ban_giao_kh_codes',
            'task_definitions',
        );

        $both_web_api_methods = array(
            'cap_nhat_trang_thai',
            'nhap_kho_nhan_vien',
        );

        if (in_array($this->action, $api_methods) || in_array($this->action, $both_web_api_methods)) {
            // disable CSRF check for API call with secret or from mobile app
            if ($this->Session->read("mobileApp")) {
                // APIs that requires signature
                if (in_array($this->action, array('cap_nhat_trang_thai'))) {
                    $shared_secret = $this->ActionLog->session_get_shared_secret($this);
                    if (empty($shared_secret)) {
                        echo json_encode(array('error' => __('Not exchange key yet')));
                        die();
                    }
                }
                $this->Security->validatePost = FALSE;
                $this->Security->csrfCheck = FALSE;
            }
            else {
                if (!in_array($this->action, $both_web_api_methods)) {
                    throw new NotFoundException();
                }
            }

            $this->autoRender = FALSE;
        }
    }


    public function beforeRender()
    {
        parent::beforeRender();

        $this->plugin = '';
        $this->layout = "Ved.default";
    }


    protected function checkCurrentTask($printer)
    {
        $taskUid = $this->ProcessWrapperApi->getTaskUid($this->action);
        if ($taskUid) {
            $rs = $this->ProcessWrapperApi->get("printers/" . str_replace('/', '.', $printer->PRINTER_CODE) . "/current_task");
            $curTask = @$rs->response;
            if ($taskUid != $curTask->tas_uid) {
                throw new ForbiddenException();
            }
        }
    }


    public function update_actions()
    {
        return new CakeResponse(
            array(
                'type' => 'json',
                'body' => json_encode(
                    array(
                        'errorCode' => 0,
                        'error'     => NULL,
                        'response'  => self::$_UPDATE_ACTIONS,
                    ))
            ));
    }


    public function task_definitions()
    {
        return new CakeResponse(
            array(
                'type' => 'json',
                'body' => json_encode(
                    array(
                        'errorCode' => 0,
                        'error'     => NULL,
                        'response'  => array_flip($this->ProcessWrapperApi->getTasks()),
                    ))
            ));
    }


    public function ban_giao_kh_codes()
    {
        $taskUid = $this->ProcessWrapperApi->getTaskUid('ban_giao_khach_hang');

        $params = array(
            'tas_uids' => $taskUid,
            'limit'    => 100,
        );

        // ignore pagination because NVRSD can hold maximum of 5 printers
        $rs = $this->ProcessWrapperApi->get('printers', $params);
        $paging = $rs->response;

        $printers = array();
        foreach ($paging->data as $printer) {
            $printers[] = array(
                'code'   => $printer->PRINTER_CODE,
                'serial' => $printer->PRINTER_SERIAL,
            );
        }

        return new CakeResponse(
            array(
                'type' => 'json',
                'body' => json_encode(
                    array(
                        'errorCode' => 0,
                        'error'     => NULL,
                        'response'  => $printers,
                    ))
            ));
    }


    private function touchSaleCafe($cyberpayId)
    {
        App::import("Model", "SaleCafe");
        $this->SaleCafe = new SaleCafe();

        $modified = date("Y-m-d H:i:s", time());

        $this->SaleCafe->updateAll(
            array('modified' => "'$modified'"),
            array('cyberpay_id' => $cyberpayId)
        );
    }

    /*
     * Printer Processing
     *
     * @author Xuan Vo <vanxuan.vo@ved.com.vn>
     *
     * @param   string $action Default is bulk
     * @param   Request $request
     *
     * @return  
     * 
     * @recommendation: use queue or worker for this task
     */
    public function process($action = 'bulk')
    {
        // Check Authourize

        if ($this->request->is('post')) {
            $params = $this->request->data;
            if (!isset($params['process_name']) || empty($params['process_name'])) {
                // Redirect with error message
            }
            $processName = $params['process_name'];
            switch ($processName) {
                case 'bulk_do_process':
                    if (!isset($params['Printer']['selectedItems']) || empty($params['Printer']['selectedItems'])) {
                        $printers = explode(';', $params['Printer']['selectedItems']);
                        // Validation, Check data...

                        // Update Records via API
                        $putParams = array();
                        foreach ($printers as $prntrCode) {
                            $prntrCode = trim($prntrCode);
                            if (empty($prntrCode)) {
                                continue;
                            }
                            $rs      = $this->ProcessWrapperApi->get("printers/{$prntrCode}/current_task");
                            $curTask = @$rs->response;
                            if (!empty($curTask) && $curTask->can_process) {
                                // Can do
                                $tas_uid = $curTask->tas_uid;
                                $task    = $this->ProcessWrapperApi->getTaskFromUid($tas_uid);
                                if ($task) {
                                    return $this->{$task}($code);
                                }
                            }
                            unset($rs, $curTask, $tas_uid, $task);
                        }
                    }
                    break;
                
                default:
                    # code...
                    break;
            }
        }
    }

}
