###############################################################################
#
# Configuration

# Directories

export DATA_DIR=$root/../../update-data
export DOC_DATA=$DATA_DIR/doc

# Libraries to build documentation for
export STANDALONE_DOCUMENTATION="geometry phoenix fusion spirit spirit/repository crc algorithm context coroutine numeric/odeint log tti functional/factory functional/forward range utility core convert test sort"

# Compiler details, leave ccache blank if you don't use it.
export CCACHE_BIN="ccache"
export CXX_BIN="g++"
export CXX_FLAGS="--std=c++0x"
export B2_TOOLSET="gcc"

# TODO: Setting DOXYGEN_BIN to anything else won't work for geometry.
# TODO: Perhaps write a shell script to the bin directory.
export DOXYGEN_BIN="doxygen"
export RAPIDXML_VERSION=1.13
export SOURCEFORGE_USERNAME=danieljames

export RAPIDXML_FILENAME=rapidxml-${RAPIDXML_VERSION}.zip
export GIT_URL=$DATA_DIR/mirror/
