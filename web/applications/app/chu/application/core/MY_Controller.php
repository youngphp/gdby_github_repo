<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class MY_Controller extends CI_Controller {
    protected $post = array();
    protected $_service_url = '';
    protected static $_app_required_fields = [];
    // post数据
    public function __construct() {
        parent::__construct();
        $this->load->library(array('UserAuth', 'Http'));
        $this->load->model(['MLog']);
        $this->load->helper(array('api_filter_fields'));
        self::$_app_required_fields = C('app_filter_fields');
        $this->post = json_decode(file_get_contents("php://input"), TRUE);
        // 从post中json字符串中解析出变量并合并到$_POST
        if(!empty($this->post)) {
            $this->post = xss_clean($this->post);
            $_POST = array_merge($_POST, $this->post);
        } else {
            $this->post = xss_clean($_POST);
        }
        $this->_select_service_by_url();
        //过滤非法访问
        //$this->_check_sign();
        // 记录系统日志
        $this->MLog->record($this->userauth->current(TRUE));
        // 激活分析器以调试程序
        //$this->output->enable_profiler(TRUE);
    }

    private function _select_service_by_url() {
        $request_url = $this->input->server('REQUEST_URI');
        $pattern = '/v\d+\//';
        if(!preg_match($pattern, $request_url)) {
            // 一期还是走原来的
            $this->_service_url = C('service.app');
        } else {
            // 二期走现在的
            $this->_service_url = C('service.s');
        }
    }

    /**
     * 验证sign
     * @author changshaoshuai@dachuwang.com
     * @since 2015-07-01
     */
    private function _check_sign() {
        $request_mothod = $_SERVER['REQUEST_METHOD'];
        if(strtoupper($request_mothod) == 'GET') {
            //get 请求直接返回
            return;
        }
        //POST请求
        $return = array(
            'status' => C('status.auth.forbidden'),
            'msg'    => '访问失败！'
        );
        //获取访问的类名，方法名
        $segments = $this->uri->segments;
        if(count($segments) < 2) {
            $this->_return_json($return);
        }

        if($segments['1'] == 'device' && $segments['2'] = 'init_device') {
            return;
        }

        if(empty($_POST['user_sign'])) {
            $this->_return_json($return);
        }

        //校验加密数据是否一致
        $res = $this->format_query('/data_encrypt/check_sign', $_POST);
        if($res['status'] != C('status.req.success')) {
            $this->_return_json($res);
        }

    }

    /**
     * @author: liaoxianwen@ymt360.com
     * @description
     * @param: array arr 需要转成json的数组
     */
    public function _return_json($arr) {
        if(in_array($this->input->server("HTTP_ORIGIN"), C("allowed_origins"))) {
            header('Access-Control-Allow-Origin: ' . $this->input->server("HTTP_ORIGIN"));
        } else {
            header('Access-Control-Allow-Origin: http://www.dachuwang.com');
        }
        header('Content-Type: application/json');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: X-Requested-With');
        header('Cache-Control: no-cache');
        echo json_encode($arr);exit;
    }

    /**
     * ajax请求通用返回接口
     * @author: yugang@ymt360.com
     * @param: result 操作结果
     * @since 2015-01-27
     */
    public function _return($result, $success_msg = '操作成功', $failure_msg = '操作失败') {
        if ($result) {
            $this->_return_json(
                array(
                    'status'    => C('status.req.success'),
                    'msg'       => $success_msg,
                )
            );
        } else {
            $this->_return_json(
                array(
                    'status'    => C('status.req.failed'),
                    'msg'       => $failure_msg,
                )
            );
        }
    }

    /**
     * 进行权限验证
     * @author yugang@ymt360.com
     * @since 2015-01-23
     */
    public function check_validation($resource, $operation, $module = '') {
        $result = $this->userauth->check_validation($resource, $operation, $module);
        if($result == C('status.auth.login_timeout')) {
            $this->_return_json(
                array(
                    'status' => C('status.auth.login_timeout'),
                    'msg'    => '登录超时，请重新登录',
                )
            );
        } elseif ($result == C('status.auth.forbidden')) {
            header('HTTP/1.0 403 Forbidden');
            echo 'You are forbidden!';
            exit;
        }
    }

    /**
     * 导出csv数据报表
     * @author yugang@ymt360.com
     * @since 2015-01-30
     */
    public function export_csv($title_arr, $data, $file_path = 'default.csv') {
        array_unshift($data, $title_arr);
        // 在公共的方法中使用导出时间不限制
        // 以防止数据表太大造成csv文件过大,造成超时
        set_time_limit(0);
        $temp_file_path = BASEPATH . '../application/temp/'. uniqid() . '.csv';
        $file = fopen($temp_file_path, 'w');
        $keys = array_keys($title_arr);
        foreach($data as $item) {
            // 对数组中的内容按照标题的顺序排序，去除不需要的内容
            $info = array();
            foreach ($keys as $v) {
                $info[$v] = isset($item[$v]) ? $item[$v] : '';
            }
            fputcsv($file, $info);
        }
        fclose($file);
        
        //在win下看utf8的csv会有点问题
        $str = file_get_contents($temp_file_path);
        $str = iconv('UTF-8', 'GBK', $str);
        unlink($temp_file_path);
        // 下载文件
        $this->load->helper('download');
        force_download($file_path, $str);
    }

    /**
     * 进行表单验证
     * @author yugang@ymt360.com
     * @description 进行表单验证，如果失败返回错误提示
     */
    public function validate_form() {
        if ($this->form_validation->run() === FALSE) {
            $this->_return_json(
                array(
                    'status'  => C('status.req.invalid'),
                    'msg'     => '请填写完整必填的信息', // 表单验证错误提示信息validation_errors()
                )
            );
        }
    }

    /**
     * 获取分页相关参数
     * @author yugang@ymt360.com
     * @since 2015-02-03
     */
    public function get_page() {
        $page = empty($_POST['currentPage']) ? 1 : $_POST['currentPage'];
        $page_size = empty($_POST['itemsPerPage']) ? 10 : $_POST['itemsPerPage'];
        $offset = $page_size * ($page - 1);
        $page = array(
            'page'      => $page,
            'offset'    => $offset,
            'page_size' => $page_size,
        );

        return $page;
    }

    /**
     * @author: liaoxianwen@dachuwang.com
     * @description 格式化数据
     */
    public function format_query($uri_string, $data = array(), $debug = FALSE) {
        $url = $this->_service_url . '/' . ltrim( $uri_string, '/');
        $return_data = $this->http->query($url, $data);
        if($debug) {
            echo $return_data;
        }
        $res = json_decode($return_data, TRUE);
        return $res;
    }

    protected static function _get_required_fields(&$filter_arr, $filter_arr_key, $required_fields) {
        if(!empty($filter_arr[$filter_arr_key])) {
            api_filter_fields($filter_arr[$filter_arr_key], $required_fields);
        }
    }

}
