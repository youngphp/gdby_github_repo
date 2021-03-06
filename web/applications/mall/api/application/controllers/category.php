<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * 货物的类型控制器
 * @author: liaoxianwen@dachuwang.com
 * @version: 1.0.0
 * @since: 2014-12-10
 */
class Category extends MY_Controller {
    private $_page_size = 10;
    public function __construct() {
        parent::__construct();
        $this->load->model(
            array(
                'MCategory',
                'MFollow_with_interest'
            )
        );
        $this->load->helper(array('product'));
        $this->load->library(array('userauth'));
    }
    /**
     * @author: liaoxianwen@dachuwang.com
     * @description
     */
    public function lists() {
        $return_data = $this->_get_lists();
        extract($return_data);
        $current_date = strtotime(date("Y-m-d", $this->input->server('REQUEST_TIME')));
        $recommends = $this->format_query('/recommend/manage', array('customer_type' => $customer_type, 'status' => C('status.common.success'), 'location_id' => $location_id, 'site_id' => $site_id, 'current_date' => $current_date));
        $data['recommends'] = [];
        if(isset($recommends['list'])) {
            $this->_get_follow_status($recommends['list']);
            $data['recommends'] = $recommends['list'];
        }
        $this->_extract_spec_up($data['recommends']);
        $this->_return_json($data);
    }
    //把推荐的规则提到上一层
    private function _extract_spec_up(&$data_list) {
        foreach($data_list as &$item) {
            extract_spec($item['products']);
        }
    }

    private function _get_follow_status(&$recommends_list) {
        $cur = $this->userauth->current(TRUE);
        $pids_str_arr = array_column($recommends_list, 'product_ids');
        $pids_str = implode(',', $pids_str_arr);
        $pids = array_unique(array_filter(explode(',', $pids_str)));
        $pids = $pids ? $pids : array(0);
        $pid_map_status = $this->MFollow_with_interest->get_lists(
            'product_id,status',
            array(
                'user_id' => empty($cur['id']) ? 0 : $cur['id'],
                'in'      => array('product_id' => $pids),
                'status'  => 1
            )
        );
        $product_id_map_status = array_column($pid_map_status, 'status', 'product_id');
        foreach($recommends_list as &$recommend) {
            foreach($recommend['products'] as &$product) {
                $product['follow_status'] = isset($product_id_map_status[$product['id']]) ? $product_id_map_status[$product['id']] : 0;
            }
        }
    }
    /**
     * @author: liaoxianwen@ymt360.com
     * @description 专门获取分类，目前是app分类页数据接口
     */
    public function cate_lists() {
        $return_data = $this->_get_lists();
        extract($return_data);
        $this->_return_json($data);
    }
    /**
     * @author: liaoxianwen@ymt360.com
     * @description 获取分类映射列表
     */
    private function _get_lists() {
        $post = $this->post;
        $site_id = $post['site_id'] = C('app_sites.chu.id');
        $post['status'] = C('status.common.success');
        // 查询所属城市
        if(!empty($post['locationId'])) {
            $location_id = $post['location_id'] = intval($post['locationId']);
        } else {
            $location_id = $post['location_id'] =  C('open_cities.beijing.id');
        }
        // 检测用户是否已经登录,
        // 登录用户不允许切换城市
        // 优先取登录用户的所在城市
        $cur = $this->userauth->current(TRUE);
        $user_info = array();
        $customer_type = C('customer.type.normal.value');
        if($cur) {
            $location_id = $post['location_id'] = $cur['province_id'];
            $local_info = $this->format_query('/location/info', array('where' => array('id' => $cur['province_id'])));
            // 所在城市info信息
            if(intval($local_info['status']) === 0) {
                $user_info = array(
                    'location_id' => $cur['province_id'],
                    'name' => $local_info['info']['name']
                );
                $post['line_id'] = $cur['line_id'];
            }
            if(!empty($cur['customer_type'])){
                $customer_type = $post['customer_type'] = $cur['customer_type'];
            }
        }

        $return_data = $this->format_query('/catemap/lists', $post);
        $data = $this->_deal_catemap_data($return_data, $user_info, $post);
        return array('data' => $data, 'customer_type' => $customer_type, 'location_id' => $location_id, 'site_id' => $site_id);
    }
    /**
     * @author: liaoxianwen@ymt360.com
     * @description 处理列表数据
     */
    private function _deal_catemap_data($return_data, $user_info, $post) {
        $data = array(
            'list' => array(
                'top' => [],
                'second' => [],
                'user_info' => $user_info
            ),
            'adv_switch_index' => FALSE,
            'status' => C('tips.code.op_success')
        );
        if($return_data) {
            $top = $second = array();
            foreach($return_data['list'] as $v) {
                if($v['upid']) {
                    $v['map_id'] = $v['id'];
                    $v['id'] = $v['origin_id'];
                }
                if($v['upid'] == 0) {
                    $top[] = $v;
                } else {
                    $second[$v['upid']][] = $v;
                }
            }

            if(isset($post['location_id']) && intval($post['location_id']) === intval(C('open_cities.beijing.id'))) {
                $adv_switch = C('advs.index');
            } else {
                $adv_switch = FALSE;
            }
            $data = array(
                'list' => array(
                    'top' => $top,
                    'second' => $second,
                    'user_info' => $user_info
                ),
                'adv_switch_index' => $adv_switch,
                'status' => $return_data['status']
            );
        }
        return $data;
    }
    /**
     * @author: liaoxianwen@dachuwang.com
     * @description 查看子分类
     */
    public function get_category_list() {
        $page = empty($this->post['page']) ? 1 : $this->post['page'];
        if(isset($this->post['upid'])) {
            $this->post['upid'] = intval($this->post['upid']);
        } else {
            $this->post['upid'] = 0;
        }
        if(isset($this->post['name'])) {
            $where['like'] = array('name'   => $this->post['name']);
        }
        if(!isset($this->post['seccate'])) {
            $where['upid'] = $this->post['upid'];
        }
        $tips = $this->format_query('/category/get_child_list', array('where' => $where, 'page' => $this->post['page']));
        $this->_return_json($tips);
    }
}
/* End of file category.php */
/* Location: :./application/controllers/category.php */
