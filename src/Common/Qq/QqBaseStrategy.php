<?php
/**
 * Created by PhpStorm.
 * User: Evcehiack
 * Date: 2017/5/22
 * Time: 22:45
 */

namespace Payment\Common\Qq;


use Payment\Common\BaseData;
use Payment\Common\BaseStrategy;
use Payment\Common\PayException;
use Payment\Common\QqConfig;
use Payment\Utils\ArrayUtil;
use Payment\Utils\Curl;
use Payment\Utils\DataParser;

/**
 * QQ基础策略类
 * Class QqBaseStrategy
 * @package Payment\Common\Qq
 */
abstract class QqBaseStrategy implements BaseStrategy
{
    /**
     * QQ的配置文件
     * @var QqConfig $config
     */
    protected $config;

    /**
     * 支付数据
     * @var BaseData $reqData
     */
    protected $reqData;

    /**
     * QqBaseStrategy constructor.
     * @param array $config
     * @throws PayException
     */
    public function __construct(array $config)
    {
        /* 设置内部字符编码为 UTF-8 */
        mb_internal_encoding("UTF-8");

        try {
            $this->config = new QqConfig($config);
        } catch (PayException $e) {
            throw $e;
        }
    }

    /**
     * 发送完了请求
     * @param string $xml
     * @return mixed
     * @throws PayException
     * @author helei
     */
    protected function sendReq($xml)
    {
        $url = $this->getReqUrl();
        if (is_null($url)) {
            throw new PayException('目前不支持该接口。请联系开发者添加');
        }

        //print_r($xml);
        $responseTxt = $this->curlPost($xml, $url);
        if ($responseTxt['error']) {
            throw new PayException('网络发生错误，请稍后再试curl返回码：' . $responseTxt['message']);
        }
        // 格式化为数组
        $retData = DataParser::toArray($responseTxt['body']);
        if ($retData['return_code'] != 'SUCCESS') {
            throw new PayException('QQ返回错误提示:' . $retData['return_msg']);
        }
        if ($retData['result_code'] != 'SUCCESS') {
            $msg = $retData['err_code_des'] ? $retData['err_code_des'] : $retData['err_msg'];
            throw new PayException('QQ返回错误提示:' . $msg);
        }

        return $retData;
    }

    /**
     * 父类仅提供基础的post请求，子类可根据需要进行重写
     * @param string $xml
     * @param string $url
     * @return array
     * @author helei
     */
    protected function curlPost($xml, $url)
    {
        $curl = new Curl();
        return $curl->set([
            'CURLOPT_HEADER'    => 0
        ])->post($xml)->submit($url);
    }

    /**
     * 获取需要的url  默认返回下单的url
     * @author helei
     * @return string|null
     */
    protected function getReqUrl()
    {
        return QqConfig::UNIFIED_URL;
    }

    /**
     * @param array $data
     * @author helei
     * @throws PayException
     * @return array|string
     */
    public function handle(array $data)
    {
        $buildClass = $this->getBuildDataClass();

        try {
            $this->reqData = new $buildClass($this->config, $data);
        } catch (PayException $e) {
            throw $e;
        }
        $this->reqData->setSign();

        $xml = DataParser::toXml($this->reqData->getData());
        $ret = $this->sendReq($xml);
        //var_dump($ret);
        // 检查返回的数据是否被篡改
        $flag = $this->verifySign($ret);
        if (!$flag) {
            throw new PayException('微信返回数据被篡改。请检查网络是否安全！');
        }

        return $this->retData($ret);
    }

    /**
     * 处理微信的返回值并返回给客户端
     * @param array $ret
     * @return mixed
     * @author helei
     */
    protected function retData(array $ret)
    {
        return $ret;
    }

    /**
     * 检查微信返回的数据是否被篡改过
     * @param array $retData
     * @return boolean
     * @author helei
     */
    protected function verifySign(array $retData)
    {
        $retSign = $retData['sign'];
        $values = ArrayUtil::removeKeys($retData, ['sign', 'sign_type']);

        $values = ArrayUtil::paraFilter($values);

        $values = ArrayUtil::arraySort($values);

        $signStr = ArrayUtil::createLinkstring($values);

        $signStr .= '&key=' . $this->config->md5Key;
        switch ($this->config->signType) {
            case 'MD5':
                $sign = md5($signStr);
                break;
            case 'HMAC-SHA256':
                $sign = hash_hmac('sha256', $signStr, $this->config->md5Key);
                break;
            default:
                $sign = '';
        }

        return strtoupper($sign) === $retSign;
    }
}