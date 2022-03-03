<?php
//直接继承基类
class IndexAct extends Base{
	//创建一个方法
	public function index(){
        #$id = Q('id%d');
        $res = db()->query("select top 10 * from conf");	//查询数据库
        $row = db()->fetch_array($res);	//获得查询结果
        $row2 = [];
/*        if($id>0){
            $row2 = db()->getOne('select * from conf where id='.$id);
        }*/
        return [time(), $row, $row2, redis()->get('yx_msg_id')];
	}
}