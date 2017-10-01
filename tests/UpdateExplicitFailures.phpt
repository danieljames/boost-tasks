<?php

use Tester\Assert;

require_once(__DIR__.'/bootstrap.php');

define('MARKUP_PREFIX', <<<EOL
<?xml version="1.0" encoding="utf-8"?>
<explicit-failures-markup xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="explicit-failures.xsd">

    <!--
    PLEASE VALIDATE THE XML BEFORE COMMITTING YOUR CHANGES!
    -->

    <!-- /////////////// Toolsets /////////////// -->
    <mark-toolset name="acc" status="required"/>
    <mark-toolset name="darwin-4.0.1" status="required"/>

EOL
);

define('MARKUP_POSTFIX', <<<EOL
    <!-- /////////////// Standard note definitions /////////////// -->

    <note id="0">
        This test fails only intermittently.
    </note>

    <note id="1">
        The failure is caused by a problem in Boost code. The Boost developers are aware of
        the problem and plan to fix it.
    </note>

    <note id="2">
        The failure is caused by a compiler bug.
    </note>

    <note id="3">
        The failure is caused by a compiler bug, which has been reported to the compiler
        supplier (or is already known to them).
    </note>

    <note id="4">
        The failure is caused by a standard library bug.
    </note>

    </explicit-failures-markup>
EOL
);

define('MARKUP_LIB_ACCUMULATORS', <<<EOL
<!-- accumulators -->
<library name="accumulators">
  <mark-unusable>
    <toolset name="sun-5.7"/>
    <toolset name="sun-5.8"/>
    <toolset name="sun-5.9"/>
    <toolset name="borland-*"/>
    <toolset name="vacpp-*"/>
    <toolset name="cray-*"/>
  </mark-unusable>
  <mark-expected-failures>
      <test name="tail_variate_means"/>
      <test name="weighted_tail_variate_means"/>
      <toolset name="gcc-4.2.1*"/>
      <note author="Boris Gubenko" refid="42"/>
  </mark-expected-failures>
  <mark-expected-failures>
      <test name="weighted_kurtosis"/>
      <toolset name="acc"/>
      <note author="Boris Gubenko" refid="38"/>
  </mark-expected-failures>
  <mark-expected-failures>
    <test name="weighted_tail_variate_means"/>
    <toolset name="hp_cxx-71*"/>
    <note author="Markus Schoepflin">
      This failure is caused by a timeout when compiling the test. It
      passes when the timeout value is increased.
    </note>
  </mark-expected-failures>
  <mark-expected-failures>
    <test name="covariance"/>
    <test name="pot_quantile"/>
    <test name="tail_variate_means"/>
    <test name="weighted_covariance"/>
    <test name="weighted_pot_quantile"/>
    <test name="weighted_tail_variate_means"/>
    <toolset name="acc"/>
    <note author="Boris Gubenko" refid="47"/>
  </mark-expected-failures>
  <mark-expected-failures>
    <test name="p_square_cumul_dist"/>
    <test name="weighted_p_square_cumul_dist"/>
    <toolset name="*"/>
    <note author="Eric Niebler" refid="53"/>
  </mark-expected-failures>
</library>


EOL
);

define('MARKUP_LIB_ALGORITHM', <<<EOL
<!-- algorithm -->
<library name="algorithm">
  <mark-expected-failures>
      <test name="empty_search_test"/>
      <test name="search_test1"/>
      <test name="search_test2"/>
      <test name="search_test3"/>
      <test name="is_permutation_test1"/>
      <toolset name="vacpp-10.1"/>
    <note author="Marshall Clow">
      These failures are caused by a lack of support/configuration for Boost.Tr1
    </note>
  </mark-expected-failures>
</library>


EOL
);

define('MARKUP_LIB_DETAIL', <<<EOL
<!-- detail -->
<library name="detail">
    <mark-expected-failures>
        <test name="correctly_disable"/>
        <test name="correctly_disable_debug"/>
        <toolset name="pathscale-4.*"/>
        <toolset name="sun-5.10"/>
        <toolset name="pgi-*"/>
        <toolset name="msvc-9.0~stlport*"/>
        <toolset name="msvc-9.0~wm5~stlport*"/>
        <note author="Daniel James">
        This indicates that forward declarations could probably be used
        for these compilers but currently aren't. All these compilers use
        STLport, which is compatible with forward declarations in some
        circumstances, but not in others. I haven't looked into how to
        determine this, so I've just set container_fwd to never forward
        declare for STLport.
        </note>
    </mark-expected-failures>

    <mark-expected-failures>
        <test name="correctly_disable"/>
        <toolset name="gcc-4.2*"/>
        <toolset name="gcc-4.3*"/>
        <toolset name="gcc-4.4*"/>
        <toolset name="gcc-4.5*"/>
        <toolset name="gcc-4.6*"/>
        <toolset name="gcc-4.7*"/>
        <toolset name="gcc-4.8*"/>
        <toolset name="gcc-4.9*"/>
        <toolset name="gcc-mingw-*"/>
        <toolset name="darwin-4.2*"/>
        <toolset name="darwin-4.3*"/>
        <toolset name="darwin-4.4*"/>
        <toolset name="clang-darwin-4.2.1"/>
        <toolset name="clang-darwin-asan"/>
        <toolset name="clang-darwin-tot"/>
        <toolset name="clang-darwin-trunk"/>
        <toolset name="clang-darwin-normal"/>
        <toolset name="clang-linux-*"/>
        <toolset name="intel-linux-*"/>
        <toolset name="intel-darwin-*"/>
        <note author="Daniel James">
            GCC's libstdc++ has a versioned namespace feature which breaks
            container forwarding. I don't know how to detect it so I'm just
            always disabling it, which means that a lot of setups which
            means that it's disabled for a lot of setups where it could
            work - which is what these failures represent.
        </note>
    </mark-expected-failures>

    <mark-expected-failures>
        <test name="container_fwd"/>
        <test name="container_fwd_debug"/>
        <test name="container_no_fwd_test"/>
        <toolset name="msvc-9.0~wm5~stlport5.2"/>
        <note author="Daniel James">
        Failing because these tests are run with warnings as errors,
        and the standard library is causing warnings.
        </note>
    </mark-expected-failures>

    <mark-expected-failures>
        <test name="container_fwd_debug"/>
        <toolset name="sun-5.10"/>
        <note author="Daniel James">
        STLport debug mode seems to be broken here.
        </note>
    </mark-expected-failures>

    <mark-expected-failures>
        <test name="container_fwd_debug"/>
        <toolset name="clang-darwin-0x"/>
        <toolset name="clang-darwin-normal"/>
        <toolset name="clang-darwin-trunk"/>
        <note author="Daniel James">
        Some old versions of GCC's libstdc++ don't work on clang with
        _GLIBCXX_DEBUG defined.
        http://lists.cs.uiuc.edu/pipermail/cfe-dev/2011-May/015178.html
        </note>
    </mark-expected-failures>
</library>


EOL
);

class UpdateExplicitFailuresTest extends \Tester\TestCase {
    function testAlmostSortedInsert() {
        Assert::same(array('hello'), UpdateExplicitFailures::almostSortedInsert(array(), 'hello'));
        Assert::same(array('hello'), UpdateExplicitFailures::almostSortedInsert(array('hello'), 'hello'));
        Assert::same(array('hello', 'world'), UpdateExplicitFailures::almostSortedInsert(array('world'), 'hello'));
        Assert::same(array('hello', 'world'), UpdateExplicitFailures::almostSortedInsert(array('hello', 'world'), 'hello'));
        Assert::same(array('A', 'B', 'D'), UpdateExplicitFailures::almostSortedInsert(array('B', 'D'), 'A'));
        Assert::same(array('B', 'C', 'D'), UpdateExplicitFailures::almostSortedInsert(array('B', 'D'), 'C'));
        Assert::same(array('B', 'D', 'E'), UpdateExplicitFailures::almostSortedInsert(array('B', 'D'), 'E'));
        Assert::same(array('A', 'D', 'B'), UpdateExplicitFailures::almostSortedInsert(array('D', 'B'), 'A'));
        Assert::same(array('D', 'B', 'C'), UpdateExplicitFailures::almostSortedInsert(array('D', 'B'), 'C'));
        Assert::same(array('D', 'B', 'E'), UpdateExplicitFailures::almostSortedInsert(array('D', 'B'), 'E'));
        Assert::same(array('A', 'B', 'F', 'D', 'G'), UpdateExplicitFailures::almostSortedInsert(array('B', 'F', 'D', 'G'), 'A'));
        Assert::same(array('B', 'C', 'F', 'D', 'H'), UpdateExplicitFailures::almostSortedInsert(array('B', 'F', 'D', 'H'), 'C'));
        Assert::same(array('B', 'F', 'D', 'E', 'H'), UpdateExplicitFailures::almostSortedInsert(array('B', 'F', 'D', 'H'), 'E'));
        Assert::same(array('B', 'F', 'D', 'G', 'H'), UpdateExplicitFailures::almostSortedInsert(array('B', 'F', 'D', 'H'), 'G'));
        Assert::same(array('B', 'F', 'D', 'H', 'I'), UpdateExplicitFailures::almostSortedInsert(array('B', 'F', 'D', 'H'), 'I'));
    }

    function testNoChangeExplicitFailures() {
        $xml = MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_LIB_DETAIL.
            MARKUP_POSTFIX;

        $update = new UpdateExplicitFailures($xml);
        Assert::same($xml, $update->getUpdatedXml());

        $update = new UpdateExplicitFailures($xml);
        $update->addLibraries($xml);
        Assert::same($xml, $update->getUpdatedXml());

        $update = new UpdateExplicitFailures($xml);
        $update->addLibraries(
            "<explicit-failures-markup>\n".
            MARKUP_LIB_DETAIL.
            MARKUP_LIB_ACCUMULATORS.
            "</explicit-failures-markup>\n");
        Assert::same($xml, $update->getUpdatedXml());
    }

    function testNewLibExplicitFailures() {
        $update = new UpdateExplicitFailures(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_DETAIL.
            MARKUP_POSTFIX);
        $update->addLibraries("<explicit-failures-markup>\n".MARKUP_LIB_ACCUMULATORS.'</explicit-failures-markup>');
        Assert::same(MARKUP_PREFIX.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_DETAIL.
            MARKUP_POSTFIX, $update->getUpdatedXml());

        $update = new UpdateExplicitFailures(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_LIB_DETAIL.
            MARKUP_POSTFIX);
        $update->addLibraries("<explicit-failures-markup><library name='example'></library></explicit-failures-markup>");
        Assert::same(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_LIB_DETAIL.
            "<library name='example'></library>\n\n".
            MARKUP_POSTFIX, $update->getUpdatedXml());
    }

    function testUpdateLibExplicitFailures() {
        $new_detail = "<library name='detail'></library>";
        $new_lib = "<library name='banzai'></library>";

        $update = new UpdateExplicitFailures(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_DETAIL.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_POSTFIX);
        $update->addLibraries("<explicit-failures-markup>{$new_detail}</explicit-failures-markup>");
        Assert::same(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            "{$new_detail}\n\n".
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_POSTFIX, $update->getUpdatedXml());
    }

    function testMultitpleUpdatesExplicitFailures() {
        $new_detail = "<library name='detail'></library>";
        $new_lib = "<library name='banzai'></library>";

        $update = new UpdateExplicitFailures(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_DETAIL.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_POSTFIX);
        $update->addLibraries("<explicit-failures-markup>{$new_detail}{$new_lib}</explicit-failures-markup>");
        Assert::same(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            "{$new_detail}\n\n".
            MARKUP_LIB_ACCUMULATORS.
            "{$new_lib}\n\n".
            MARKUP_POSTFIX, $update->getUpdatedXml());

        $update = new UpdateExplicitFailures(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_DETAIL.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_POSTFIX);
        $update->addLibraries($new_detail);
        $update->addLibraries("<explicit-failures-markup>{$new_lib}</explicit-failures-markup>");
        $update->addLibraries(MARKUP_PREFIX.MARKUP_LIB_ACCUMULATORS.MARKUP_POSTFIX);
        Assert::same(MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            "{$new_detail}\n\n".
            MARKUP_LIB_ACCUMULATORS.
            "{$new_lib}\n\n".
            MARKUP_POSTFIX, $update->getUpdatedXml());
    }

    function testLintFailures() {
        $xml = MARKUP_PREFIX.
            MARKUP_LIB_ALGORITHM.
            MARKUP_LIB_ACCUMULATORS.
            MARKUP_LIB_DETAIL.
            MARKUP_POSTFIX;

        Assert::exception(function () {
            $update = new UpdateExplicitFailures(MARKUP_PREFIX);
        }, 'RuntimeException');

        $update = new UpdateExplicitFailures(MARKUP_PREFIX.MARKUP_LIB_DETAIL.MARKUP_POSTFIX);
        Assert::exception(function () use ($update) {
            $update->addLibraries(MARKUP_PREFIX.'<library>'.MARKUP_POSTFIX);
        }, 'RuntimeException');
    }
}

$test = new UpdateExplicitFailuresTest();
$test->run();