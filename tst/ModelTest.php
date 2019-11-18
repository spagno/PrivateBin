<?php

use Identicon\Identicon;
use PrivateBin\Configuration;
use PrivateBin\Data\Database;
use PrivateBin\Model;
use PrivateBin\Model\Comment;
use PrivateBin\Model\Paste;
use PrivateBin\Persistence\ServerSalt;
use PrivateBin\Persistence\TrafficLimiter;
use PrivateBin\Vizhash16x16;

class ModelTest extends PHPUnit_Framework_TestCase
{
    private $_conf;

    private $_model;

    protected $_path;

    public function setUp()
    {
        /* Setup Routine */
        $this->_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'privatebin_data';
        if (!is_dir($this->_path)) {
            mkdir($this->_path);
        }
        ServerSalt::setPath($this->_path);
        $options                   = parse_ini_file(CONF_SAMPLE, true);
        $options['purge']['limit'] = 0;
        $options['model']          = array(
            'class' => 'Database',
        );
        $options['model_options'] = array(
            'dsn' => 'sqlite::memory:',
            'usr' => null,
            'pwd' => null,
            'opt' => array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION),
        );
        Helper::confBackup();
        Helper::createIniFile(CONF, $options);
        $this->_conf            = new Configuration;
        $this->_model           = new Model($this->_conf);
        $_SERVER['REMOTE_ADDR'] = '::1';
    }

    public function tearDown()
    {
        /* Tear Down Routine */
        unlink(CONF);
        Helper::confRestore();
        Helper::rmDir($this->_path);
    }

    public function testBasicWorkflow()
    {
        // storing pastes
        $pasteData = Helper::getPastePost();
        unset($pasteData['meta']['created'], $pasteData['meta']['salt']);
        $this->_model->getPaste(Helper::getPasteId())->delete();
        $paste = $this->_model->getPaste(Helper::getPasteId());
        $this->assertFalse($paste->exists(), 'paste does not yet exist');

        $paste = $this->_model->getPaste();
        $paste->setData($pasteData);
        $paste->store();

        $paste = $this->_model->getPaste(Helper::getPasteId());
        $this->assertTrue($paste->exists(), 'paste exists after storing it');
        $paste = $paste->get();
        unset(
            $pasteData['meta'],
            $paste['meta'],
            $paste['comments'],
            $paste['comment_count'],
            $paste['comment_offset'],
            $paste['@context']
        );
        $this->assertEquals($pasteData, $paste);

        // storing comments
        $commentData = Helper::getCommentPost();
        $paste       = $this->_model->getPaste(Helper::getPasteId());
        $comment     = $paste->getComment(Helper::getPasteId(), Helper::getCommentId());
        $this->assertFalse($comment->exists(), 'comment does not yet exist');

        $comment = $paste->getComment(Helper::getPasteId());
        $comment->setData($commentData);
        $comment->store();

        $comments = $this->_model->getPaste(Helper::getPasteId())->get()['comments'];
        $this->assertTrue(count($comments) === 1, 'comment exists after storing it');
        $commentData['id']              = Helper::getPasteId();
        $commentData['meta']['created'] = current($comments)['meta']['created'];
        $commentData['meta']['icon']    = current($comments)['meta']['icon'];
        $this->assertEquals($commentData, current($comments));

        // deleting pastes
        $this->_model->getPaste(Helper::getPasteId())->delete();
        $paste = $this->_model->getPaste(Helper::getPasteId());
        $this->assertFalse($paste->exists(), 'paste successfully deleted');
        $this->assertEquals(array(), $paste->getComments(), 'comment was deleted with paste');
    }

    public function testCommentDefaults()
    {
        $comment = new Comment(
            $this->_conf,
            forward_static_call(
                'PrivateBin\\Data\\' . $this->_conf->getKey('class', 'model') . '::getInstance',
                $this->_conf->getSection('model_options')
            )
        );
        $comment->setPaste($this->_model->getPaste(Helper::getPasteId()));
        $this->assertEquals(Helper::getPasteId(), $comment->getParentId(), 'comment parent ID gets initialized to paste ID');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 75
     */
    public function testPasteDuplicate()
    {
        $pasteData = Helper::getPastePost();

        $this->_model->getPaste(Helper::getPasteId())->delete();
        $paste = $this->_model->getPaste();
        $paste->setData($pasteData);
        $paste->store();

        $paste = $this->_model->getPaste();
        $paste->setData($pasteData);
        $paste->store();
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 69
     */
    public function testCommentDuplicate()
    {
        $pasteData   = Helper::getPastePost();
        $commentData = Helper::getCommentPost();
        $this->_model->getPaste(Helper::getPasteId())->delete();

        $paste = $this->_model->getPaste();
        $paste->setData($pasteData);
        $paste->store();

        $comment = $paste->getComment(Helper::getPasteId());
        $comment->setData($commentData);
        $comment->store();

        $comment = $paste->getComment(Helper::getPasteId());
        $comment->setData($commentData);
        $comment->store();
    }

    public function testImplicitDefaults()
    {
        $pasteData   = Helper::getPastePost();
        $commentData = Helper::getCommentPost();
        $this->_model->getPaste(Helper::getPasteId())->delete();

        $paste = $this->_model->getPaste();
        $paste->setData($pasteData);
        $paste->store();

        $comment = $paste->getComment(Helper::getPasteId());
        $comment->setData($commentData);
        $comment->get();
        $comment->store();

        $identicon = new Identicon();
        $pngdata   = $identicon->getImageDataUri(TrafficLimiter::getHash(), 16);
        $comment   = current($this->_model->getPaste(Helper::getPasteId())->get()['comments']);
        $this->assertEquals($pngdata, $comment['meta']['icon'], 'icon gets set');
    }

    public function testPasteIdValidation()
    {
        $this->assertTrue(Paste::isValidId('a242ab7bdfb2581a'), 'valid paste id');
        $this->assertFalse(Paste::isValidId('foo'), 'invalid hex values');
        $this->assertFalse(Paste::isValidId('../bar/baz'), 'path attack');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 64
     */
    public function testInvalidPaste()
    {
        $this->_model->getPaste(Helper::getPasteId())->delete();
        $paste = $this->_model->getPaste(Helper::getPasteId());
        $paste->get();
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 60
     */
    public function testInvalidPasteId()
    {
        $this->_model->getPaste('');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 62
     */
    public function testInvalidComment()
    {
        $paste = $this->_model->getPaste();
        $paste->getComment(Helper::getPasteId());
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 67
     */
    public function testInvalidCommentDeletedPaste()
    {
        $pasteData = Helper::getPastePost();
        $paste     = $this->_model->getPaste(Helper::getPasteId());
        $paste->setData($pasteData);
        $paste->store();

        $comment = $paste->getComment(Helper::getPasteId());
        $paste->delete();
        $comment->store();
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 68
     */
    public function testInvalidCommentData()
    {
        $pasteData             = Helper::getPastePost();
        $pasteData['adata'][2] = 0;
        $paste                 = $this->_model->getPaste(Helper::getPasteId());
        $paste->setData($pasteData);
        $paste->store();

        $comment = $paste->getComment(Helper::getPasteId());
        $comment->store();
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 65
     */
    public function testInvalidCommentParent()
    {
        $paste     = $this->_model->getPaste(Helper::getPasteId());
        $comment   = $paste->getComment('');
        $comment->store();
    }

    public function testExpiration()
    {
        $pasteData = Helper::getPastePost();
        $this->_model->getPaste(Helper::getPasteId())->delete();
        $paste = $this->_model->getPaste(Helper::getPasteId());
        $this->assertFalse($paste->exists(), 'paste does not yet exist');

        $paste = $this->_model->getPaste();
        $paste->setData($pasteData);
        $paste->store();

        $paste = $paste->get();
        $this->assertEquals((float) 300, (float) $paste['meta']['time_to_live'], 'remaining time is set correctly', 1.0);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionCode 64
     */
    public function testCommentDeletion()
    {
        $pasteData = Helper::getPastePost();
        $this->_model->getPaste(Helper::getPasteId())->delete();

        $paste = $this->_model->getPaste();
        $paste->setData($pasteData);
        $paste->store();
        $paste->getComment(Helper::getPasteId())->delete();
    }

    public function testPurge()
    {
        $conf  = new Configuration;
        $store = Database::getInstance($conf->getSection('model_options'));
        $store->delete(Helper::getPasteId());
        $expired = Helper::getPaste(2, array('expire_date' => 1344803344));
        $paste   = Helper::getPaste(2, array('expire_date' => time() + 3600));
        $keys    = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'x', 'y', 'z');
        $ids     = array();
        foreach ($keys as $key) {
            $ids[$key] = hash('fnv164', $key);
            $store->delete($ids[$key]);
            $this->assertFalse($store->exists($ids[$key]), "paste $key does not yet exist");
            if (in_array($key, array('x', 'y', 'z'))) {
                $this->assertTrue($store->create($ids[$key], $paste), "store $key paste");
            } else {
                $this->assertTrue($store->create($ids[$key], $expired), "store $key paste");
            }
            $this->assertTrue($store->exists($ids[$key]), "paste $key exists after storing it");
        }
        $this->_model->purge(10);
        foreach ($ids as $key => $id) {
            if (in_array($key, array('x', 'y', 'z'))) {
                $this->assertTrue($this->_model->getPaste($id)->exists(), "paste $key exists after purge");
                $this->_model->getPaste($id)->delete();
            } else {
                $this->assertFalse($this->_model->getPaste($id)->exists(), "paste $key was purged");
            }
        }
    }

    public function testCommentWithDisabledVizhash()
    {
        $options                 = parse_ini_file(CONF, true);
        $options['main']['icon'] = 'none';
        $options['model']        = array(
            'class' => 'Database',
        );
        $options['model_options'] = array(
            'dsn' => 'sqlite::memory:',
            'usr' => null,
            'pwd' => null,
            'opt' => array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION),
        );
        Helper::createIniFile(CONF, $options);
        $model = new Model(new Configuration);

        $pasteData = Helper::getPastePost();
        $this->_model->getPaste(Helper::getPasteId())->delete();
        $paste = $model->getPaste(Helper::getPasteId());
        $this->assertFalse($paste->exists(), 'paste does not yet exist');

        $paste = $model->getPaste();
        $paste->setData($pasteData);
        $paste->store();

        $paste = $model->getPaste(Helper::getPasteId());
        $this->assertTrue($paste->exists(), 'paste exists after storing it');

        // storing comments
        $commentData = Helper::getCommentPost();
        unset($commentData['meta']['icon']);
        $paste       = $model->getPaste(Helper::getPasteId());
        $comment     = $paste->getComment(Helper::getPasteId(), Helper::getPasteId());
        $this->assertFalse($comment->exists(), 'comment does not yet exist');

        $comment = $paste->getComment(Helper::getPasteId());
        $comment->setData($commentData);
        $comment->store();

        $comment = $paste->getComment(Helper::getPasteId(), Helper::getPasteId());
        $this->assertTrue($comment->exists(), 'comment exists after storing it');

        $comment = current($this->_model->getPaste(Helper::getPasteId())->get()['comments']);
        $this->assertFalse(array_key_exists('icon', $comment['meta']), 'icon was not generated');
    }

    public function testCommentVizhash()
    {
        $options                 = parse_ini_file(CONF, true);
        $options['main']['icon'] = 'vizhash';
        $options['model']        = array(
            'class' => 'Database',
        );
        $options['model_options'] = array(
            'dsn' => 'sqlite::memory:',
            'usr' => null,
            'pwd' => null,
            'opt' => array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION),
        );
        Helper::createIniFile(CONF, $options);
        $model = new Model(new Configuration);

        $pasteData   = Helper::getPastePost();
        $commentData = Helper::getCommentPost();
        $model->getPaste(Helper::getPasteId())->delete();

        $paste = $model->getPaste();
        $paste->setData($pasteData);
        $paste->store();

        $comment = $paste->getComment(Helper::getPasteId());
        $comment->setData($commentData);
        $comment->store();

        $vz        = new Vizhash16x16();
        $pngdata   = 'data:image/png;base64,' . base64_encode($vz->generate(TrafficLimiter::getHash()));
        $comment   = current($this->_model->getPaste(Helper::getPasteId())->get()['comments']);
        $this->assertEquals($pngdata, $comment['meta']['icon'], 'nickname triggers vizhash to be set');
    }
}
