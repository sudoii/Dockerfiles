<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * Typecho Blog Platform
 *
 * @copyright  Copyright (c) 2008 Typecho team (http://www.typecho.org)
 * @license    GNU General Public License 2.0
 * @version    $Id$
 */

/**
 * 数据库Pdo_Pgsql适配器
 *
 * @package Db
 */
class Typecho_Db_Adapter_Pdo_Pgsql extends Typecho_Db_Adapter_Pdo
{
    /**
     * 主键列表
     *
     * @var array
     */
    private $_pk = array();

    /**
     * 兼容的插入模式
     *
     * @var bool
     */
    private $_compatibleInsert = false;

    /**
     * 判断适配器是否可用
     *
     * @access public
     * @return boolean
     */
    public static function isAvailable()
    {
        return parent::isAvailable() && in_array('pgsql', PDO::getAvailableDrivers());
    }

    /**
     * 清空数据表
     *
     * @param string $table
     * @param mixed $handle 连接对象
     * @return mixed|void
     * @throws Typecho_Db_Exception
     */
    public function truncate($table, $handle)
    {
        $this->query('TRUNCATE TABLE ' . $this->quoteColumn($table) . ' RESTART IDENTITY', $handle);
    }

    /**
     * 初始化数据库
     *
     * @param Typecho_Config $config 数据库配置
     * @access public
     * @return PDO
     */
    public function init(Typecho_Config $config)
    {
        $pdo = new PDO("pgsql:dbname={$config->database};host={$config->host};port={$config->port}", $config->user, $config->password);
        $pdo->exec("SET NAMES '{$config->charset}'");
        return $pdo;
    }

    /**
     * 覆盖标准动作
     * fix #710
     *
     * @param string $query
     * @param mixed $handle
     * @param int $op
     * @param null $action
     * @param null $table
     * @return resource
     * @throws Typecho_Db_Exception
     */
    public function query($query, $handle, $op = Typecho_Db::READ, $action = NULL, $table = NULL)
    {
        if (Typecho_Db::INSERT == $action && !empty($table)) {
            if (!isset($this->_pk[$table])) {
                $result = $handle->query("SELECT               
  pg_attribute.attname, 
  format_type(pg_attribute.atttypid, pg_attribute.atttypmod) 
FROM pg_index, pg_class, pg_attribute, pg_namespace 
WHERE 
  pg_class.oid = " . $this->quoteValue($table) . "::regclass AND 
  indrelid = pg_class.oid AND 
  nspname = 'public' AND 
  pg_class.relnamespace = pg_namespace.oid AND 
  pg_attribute.attrelid = pg_class.oid AND 
  pg_attribute.attnum = any(pg_index.indkey)
 AND indisprimary")->fetch(PDO::FETCH_ASSOC);

                if (!empty($result)) {
                    $this->_pk[$table] = $result['attname'];
                }
            }

            // 使用兼容模式监听插入结果
            if (isset($this->_pk[$table])) {
                $this->_compatibleInsert = true;
                $query .= ' RETURNING ' . $this->quoteColumn($this->_pk[$table]);
            }
        }

        return parent::query($query, $handle, $op, $action, $table); // TODO: Change the autogenerated stub
    }


    /**
     * 对象引号过滤
     *
     * @access public
     * @param string $string
     * @return string
     */
    public function quoteColumn($string)
    {
        return '"' . $string . '"';
    }

    /**
     * 合成查询语句
     *
     * @access public
     * @param array $sql 查询对象词法数组
     * @return string
     */
    public function parseSelect(array $sql)
    {
        if (!empty($sql['join'])) {
            foreach ($sql['join'] as $val) {
                list($table, $condition, $op) = $val;
                $sql['table'] = "{$sql['table']} {$op} JOIN {$table} ON {$condition}";
            }
        }

        $sql['limit'] = (0 == strlen($sql['limit'])) ? NULL : ' LIMIT ' . $sql['limit'];
        $sql['offset'] = (0 == strlen($sql['offset'])) ? NULL : ' OFFSET ' . $sql['offset'];

        return 'SELECT ' . $sql['fields'] . ' FROM ' . $sql['table'] .
        $sql['where'] . $sql['group'] . $sql['having'] . $sql['order'] . $sql['limit'] . $sql['offset'];
    }

    /**
     * 取出最后一次插入返回的主键值
     *
     * @param resource $resource 查询的资源数据
     * @param mixed $handle 连接对象
     * @return integer
     */
    public function lastInsertId($resource, $handle)
    {
        if ($this->_compatibleInsert) {
            $this->_compatibleInsert = false;
            return $resource->fetchColumn(0);
        } else if ($handle->query('SELECT oid FROM pg_class WHERE relname = ' . $this->quoteValue($this->_lastTable . '_seq'))->fetchAll()) {
            /** 查看是否存在序列,可能需要更严格的检查 */
            return $handle->lastInsertId($this->_lastTable . '_seq');
        }

        return 0;
    }
}
