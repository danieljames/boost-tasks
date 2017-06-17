<?php

use Tester\Assert;
use BoostTasks\Documentation;

require_once(__DIR__.'/bootstrap.php');

class DocumentationTest extends TestBase
{
    function testGetFileDetails() {
        $files = Documentation::getPrioritizedDownloads(json_decode(
            '[{"name":"boost_1_62_0.7z","path":"boost_1_62_0.7z","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:44:07.707Z","size":74669454,"sha1":"73cd8bc21d319a14ce225b7cb72a24ddfebf4098","sha256":"459147caed63e129c7841fa6046828cfa70a69c102dbb52e7186629172404e20"},{"name":"boost_1_62_0.7z.asc","path":"boost_1_62_0.7z.asc","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:44:18.557Z","size":821,"sha1":"5c982de8e3af6c145384afb60a12bbcc872f1170","sha256":"05eb868f3ba7eed17e3e455c9753f7e441e5b5b0513d021d57c6294b79f6c6d8"},{"name":"boost_1_62_0.tar.bz2","path":"boost_1_62_0.tar.bz2","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:43:49.478Z","size":88784711,"sha1":"2cd2843c55b87fce62f4751c1077f60e8909a5a8","sha256":"66dbe3586db64a2c9087ddcdc266fea8bdcdff515b4a06ba1c235e8631bcaa4b"},{"name":"boost_1_62_0.tar.bz2.asc","path":"boost_1_62_0.tar.bz2.asc","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:44:02.290Z","size":821,"sha1":"0bc7fd4d002f678651cd1c1a10dc4f03e5cdb38a","sha256":"1cf2de01919bc01c49c99eeac51a33246ff9589749c6bfd8c775c21944a7834f"},{"name":"boost_1_62_0.tar.gz","path":"boost_1_62_0.tar.gz","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:43:55.469Z","size":110025970,"sha1":"a993c8150de20dbac4021959cd7485861dc12e06","sha256":"bf2fcc47120b58ecda6581121b4b785d00e394f7fc365f98f669ffe51f2e8689"},{"name":"boost_1_62_0.tar.gz.asc","path":"boost_1_62_0.tar.gz.asc","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:44:11.717Z","size":821,"sha1":"429dee8aea68a644452cf46be944b73de6ac6d16","sha256":"9eaaa4c798e77741aced2594c1ea061f09c52a049f467017a6a4229a4f38cfbf"},{"name":"boost_1_62_0.zip","path":"boost_1_62_0.zip","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:44:15.994Z","size":167336405,"sha1":"b45d64a38c6195572057fe9874750c7fa329e274","sha256":"0caa60780b58374889926c2d803df429a9ca5987b4c30249e086a1624fa6573b"},{"name":"boost_1_62_0.zip.asc","path":"boost_1_62_0.zip.asc","repo":"develop","package":"snapshot","version":"77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7","owner":"boostorg","created":"2016-07-20T15:44:33.735Z","size":821,"sha1":"dc38fd803ba3c666412759100346d9afd06e6df5","sha256":"4fdc44d7333c0ae29c8d27487fbce714ee31f8b5ec0e84560e9eb1bc78266e88"}]'
        ));
        foreach($files as $index => $file) {
            Assert::equal('develop', $file->repo);
            Assert::equal('snapshot', $file->package);
            Assert::equal('77fe1aff5f0bdd78ff2ebb65f91b4029ec8547d7', $file->version);
            Assert::equal('boostorg', $file->owner);
        }
        Assert::equal(array(0,1,2), array_keys($files));
        Assert::equal('boost_1_62_0.tar.bz2', $files[0]->name);
        Assert::equal('boost_1_62_0.tar.bz2', $files[0]->path);
        Assert::equal('2016-07-20T15:43:49.478Z', $files[0]->created);
        Assert::equal(88784711, $files[0]->size);
        Assert::equal('2cd2843c55b87fce62f4751c1077f60e8909a5a8', $files[0]->sha1);
        Assert::equal('66dbe3586db64a2c9087ddcdc266fea8bdcdff515b4a06ba1c235e8631bcaa4b', $files[0]->sha256);
        Assert::equal('boost_1_62_0.tar.gz', $files[1]->name);
        Assert::equal('boost_1_62_0.tar.gz', $files[1]->path);
        Assert::equal('2016-07-20T15:43:55.469Z', $files[1]->created);
        Assert::equal(110025970, $files[1]->size);
        Assert::equal('a993c8150de20dbac4021959cd7485861dc12e06', $files[1]->sha1);
        Assert::equal('bf2fcc47120b58ecda6581121b4b785d00e394f7fc365f98f669ffe51f2e8689', $files[1]->sha256);
        Assert::equal('boost_1_62_0.zip', $files[2]->name);
        Assert::equal('boost_1_62_0.zip', $files[2]->path);
        Assert::equal('2016-07-20T15:44:15.994Z', $files[2]->created);
        Assert::equal(167336405, $files[2]->size);
        Assert::equal('b45d64a38c6195572057fe9874750c7fa329e274', $files[2]->sha1);
        Assert::equal('0caa60780b58374889926c2d803df429a9ca5987b4c30249e086a1624fa6573b', $files[2]->sha256);
    }

    function testSortByVersionDate() {
        // If there are files from different versions, prioritize the more
        // recent file.
        //
        // TODO: Deal with weird edge case where the dates for two versions
        //       overlap, so that it isn't a consistent order. Seems very
        //       unlikely, and if it does happen, no realy way to work out
        //       the more recent ordering without checking the git repo,
        //       which I don't really want to do.
        //       Maybe use the earlist date for a given version?

        $json = array(
            (object) array(
                'name' => 'boost_1_62_0.tar.bz2',
                'path' => 'boost_1_62_0.tar.bz2',
                'version' => 'aaaaaaaa',
                'created' => '2016-07-04',
            ),
            (object) array(
                'name' => 'boost_1_62_0.zip',
                'path' => 'boost_1_62_0.zip',
                'version' => 'bbbbbbbbb',
                'created' => '2016-07-05',
            ),
            (object) array(
                'name' => 'boost_1_62_0.tar.gz',
                'path' => 'boost_1_62_0.tar.gz',
                'version' => 'bbbbbbbbb',
                'created' => '2016-07-05',
            ),
            (object) array(
                'name' => 'boost_1_62_0.7z',
                'path' => 'boost_1_62_0.7z',
                'version' => 'bbbbbbbbb',
                'created' => '2016-07-05',
            ),
        );

        $files = Documentation::getPrioritizedDownloads($json);

        Assert::equal(array(0,1,2), array_keys($files));
        Assert::equal('boost_1_62_0.tar.gz', $files[0]->name);
        Assert::equal('boost_1_62_0.zip', $files[1]->name);
        Assert::equal('boost_1_62_0.tar.bz2', $files[2]->name);
    }

    function testSortByVersionDateDuplicateExtension() {
        $json = array(
            (object) array(
                'name' => 'boost_1_62_0.tar.bz2',
                'path' => 'boost_1_62_0.tar.bz2',
                'version' => 'aaaaaaaa',
                'created' => '2016-07-04',
            ),
            (object) array(
                'name' => 'boost_1_62_0.tar.bz2',
                'path' => 'boost_1_62_0.tar.bz2',
                'version' => 'bbbbbbbbb',
                'created' => '2016-07-05',
            ),
        );

        $files = Documentation::getPrioritizedDownloads($json);
        Assert::equal(array(0,1), array_keys($files));
        Assert::equal('boost_1_62_0.tar.bz2', $files[0]->name);
        Assert::equal('boost_1_62_0.tar.bz2', $files[1]->name);
        Assert::equal('bbbbbbbbb', $files[0]->version);
        Assert::equal('aaaaaaaa', $files[1]->version);
    }

    function testNoMatches() {
        $json = array(
            (object) array(
                'name' => 'boost_1_62_0.7z',
                'path' => 'boost_1_62_0.7z',
                'version' => 'aaaaaaaa',
                'created' => '2016-07-04',
            ),
            (object) array(
                'name' => 'boost_1_62_0.txt',
                'path' => 'boost_1_62_0.txt',
                'version' => 'bbbbbbbbb',
                'created' => '2016-07-05',
            ),
        );

        Assert::exception(function() use($json) {
            Documentation::getPrioritizedDownloads($json);
        }, 'RuntimeException', '#Unable to find file#');
    }

    function testEmptyDownload() {
        Assert::exception(function() {
            Documentation::getPrioritizedDownloads(array());
        }, 'RuntimeException', '#Unable to find file#');
    }
}

$test = new DocumentationTest();
$test->run();
