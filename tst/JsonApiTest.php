<?php

use PrivateBin\Controller;
use PrivateBin\Data\Filesystem;
use PrivateBin\Persistence\ServerSalt;
use PrivateBin\Request;

class JsonApiTest extends PHPUnit_Framework_TestCase
{
    protected $_model;

    protected $_path;

    public function setUp()
    {
        /* Setup Routine */
        $this->_path  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'privatebin_data';
        $this->_model = Filesystem::getInstance(array('dir' => $this->_path));
        ServerSalt::setPath($this->_path);

        $_POST   = array();
        $_GET    = array();
        $_SERVER = array();
        if ($this->_model->exists(Helper::getPasteId())) {
            $this->_model->delete(Helper::getPasteId());
        }
        $options                         = parse_ini_file(CONF_SAMPLE, true);
        $options['purge']['dir']         = $this->_path;
        $options['traffic']['dir']       = $this->_path;
        $options['model_options']['dir'] = $this->_path;
        Helper::confBackup();
        Helper::createIniFile(CONF, $options);
    }

    public function tearDown()
    {
        /* Tear Down Routine */
        unlink(CONF);
        Helper::confRestore();
        Helper::rmDir($this->_path);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCreate()
    {
        $options                     = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson();
        $file  = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD']        = 'POST';
        $_SERVER['REMOTE_ADDR']           = '::1';
        $_SERVER['REQUEST_URI']           = '/';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        $this->assertEquals(0, $response['status'], 'outputs status');
        $this->assertStringEndsWith('?' . $response['id'], $response['url'], 'returned URL points to new paste');
        $this->assertTrue($this->_model->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_model->read($response['id']);
        $this->assertEquals(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testPut()
    {
        $options                     = parse_ini_file(CONF, true);
        $options['traffic']['limit'] = 0;
        Helper::createIniFile(CONF, $options);
        $paste = Helper::getPasteJson();
        $file  = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, $paste);
        Request::setInputStream($file);
        $_SERVER['QUERY_STRING']          = Helper::getPasteId();
        $_GET[Helper::getPasteId()]       = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD']        = 'PUT';
        $_SERVER['REMOTE_ADDR']           = '::1';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        unlink($file);
        $response = json_decode($content, true);
        $this->assertEquals(0, $response['status'], 'outputs status');
        $this->assertEquals(Helper::getPasteId(), $response['id'], 'outputted paste ID matches input');
        $this->assertStringEndsWith('?' . $response['id'], $response['url'], 'returned URL points to new paste');
        $this->assertTrue($this->_model->exists($response['id']), 'paste exists after posting data');
        $paste = $this->_model->read($response['id']);
        $this->assertEquals(
            hash_hmac('sha256', $response['id'], $paste['meta']['salt']),
            $response['deletetoken'],
            'outputs valid delete token'
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testDelete()
    {
        $this->_model->create(Helper::getPasteId(), Helper::getPaste());
        $this->assertTrue($this->_model->exists(Helper::getPasteId()), 'paste exists before deleting data');
        $paste = $this->_model->read(Helper::getPasteId());
        $file  = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, json_encode(array(
            'deletetoken' => hash_hmac('sha256', Helper::getPasteId(), $paste['meta']['salt']),
        )));
        Request::setInputStream($file);
        $_SERVER['QUERY_STRING']          = Helper::getPasteId();
        $_GET[Helper::getPasteId()]       = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD']        = 'DELETE';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        unlink($file);
        $response = json_decode($content, true);
        $this->assertEquals(0, $response['status'], 'outputs status');
        $this->assertFalse($this->_model->exists(Helper::getPasteId()), 'paste successfully deleted');
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteWithPost()
    {
        $this->_model->create(Helper::getPasteId(), Helper::getPaste());
        $this->assertTrue($this->_model->exists(Helper::getPasteId()), 'paste exists before deleting data');
        $paste = $this->_model->read(Helper::getPasteId());
        $file  = tempnam(sys_get_temp_dir(), 'FOO');
        file_put_contents($file, json_encode(array(
            'pasteid'     => Helper::getPasteId(),
            'deletetoken' => hash_hmac('sha256', Helper::getPasteId(), $paste['meta']['salt']),
        )));
        Request::setInputStream($file);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        $_SERVER['REQUEST_METHOD']        = 'POST';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        $this->assertEquals(0, $response['status'], 'outputs status');
        $this->assertFalse($this->_model->exists(Helper::getPasteId()), 'paste successfully deleted');
    }

    /**
     * @runInSeparateProcess
     */
    public function testRead()
    {
        $paste                           = Helper::getPaste();
        $this->_model->create(Helper::getPasteId(), $paste);
        $_SERVER['QUERY_STRING']          = Helper::getPasteId();
        $_GET[Helper::getPasteId()]       = '';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'JSONHttpRequest';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $response = json_decode($content, true);
        $this->assertEquals(0, $response['status'], 'outputs success status');
        $this->assertEquals(Helper::getPasteId(), $response['id'], 'outputs data correctly');
        $this->assertStringEndsWith('?' . $response['id'], $response['url'], 'returned URL points to new paste');
        $this->assertEquals($paste['ct'], $response['ct'], 'outputs data correctly');
        $this->assertEquals($paste['meta']['created'], $response['meta']['created'], 'outputs postdate correctly');
        $this->assertEquals(0, $response['comment_count'], 'outputs comment_count correctly');
        $this->assertEquals(0, $response['comment_offset'], 'outputs comment_offset correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testJsonLdPaste()
    {
        $paste = Helper::getPaste();
        $this->_model->create(Helper::getPasteId(), $paste);
        $_GET['jsonld'] = 'paste';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $this->assertEquals(str_replace(
                '?jsonld=',
                '/?jsonld=',
                file_get_contents(PUBLIC_PATH . '/js/paste.jsonld')
            ), $content, 'outputs data correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testJsonLdComment()
    {
        $paste = Helper::getPaste();
        $this->_model->create(Helper::getPasteId(), $paste);
        $_GET['jsonld'] = 'comment';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $this->assertEquals(str_replace(
                '?jsonld=',
                '/?jsonld=',
                file_get_contents(PUBLIC_PATH . '/js/comment.jsonld')
            ), $content, 'outputs data correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testJsonLdPasteMeta()
    {
        $paste = Helper::getPaste();
        $this->_model->create(Helper::getPasteId(), $paste);
        $_GET['jsonld'] = 'pastemeta';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $this->assertEquals(str_replace(
                '?jsonld=',
                '/?jsonld=',
                file_get_contents(PUBLIC_PATH . '/js/pastemeta.jsonld')
            ), $content, 'outputs data correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testJsonLdCommentMeta()
    {
        $paste = Helper::getPaste();
        $this->_model->create(Helper::getPasteId(), $paste);
        $_GET['jsonld'] = 'commentmeta';
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $this->assertEquals(str_replace(
                '?jsonld=',
                '/?jsonld=',
                file_get_contents(PUBLIC_PATH . '/js/commentmeta.jsonld')
            ), $content, 'outputs data correctly');
    }

    /**
     * @runInSeparateProcess
     */
    public function testJsonLdInvalid()
    {
        $paste = Helper::getPaste();
        $this->_model->create(Helper::getPasteId(), $paste);
        $_GET['jsonld'] = CONF;
        ob_start();
        new Controller;
        $content = ob_get_contents();
        ob_end_clean();
        $this->assertEquals('{}', $content, 'does not output nasty data');
    }
}
