###############################################################################
#
# Configuration

# Directories

# TODO: Would be better to use the value from config.json
export DATA_DIR=$root/../../update-data
export DOC_DATA=$DATA_DIR/doc

# Libraries to build documentation for
export STANDALONE_DOCUMENTATION="geometry phoenix fusion spirit spirit/repository algorithm context coroutine coroutine2 numeric/odeint log tti functional/factory functional/forward range utility core convert test sort bind tuple python vmd"

# Compiler details, leave ccache blank if you don't use it.
#export CCACHE_BIN="ccache"
export CCACHE_BIN=
export CXX_BIN="g++"
export CXX_FLAGS="--std=c++11"
export B2_TOOLSET="gcc"

# TODO: Setting DOXYGEN_BIN to anything else won't work for geometry.
# TODO: Perhaps write a shell script to the bin directory.
export DOXYGEN_BIN="doxygen"
export RAPIDXML_VERSION=1.13
export DOCUTILS_VERSION=0.12
export DOCBOOK_XSL_VERSION=1.78.1
export DOCBOOK_DTD_VERSION=4.2
export SOURCEFORGE_USERNAME=danieljames

export RAPIDXML_FILENAME=rapidxml-${RAPIDXML_VERSION}.zip
export DOCUTILS_FILENAME=docutils-${DOCUTILS_VERSION}.tar.gz
export DOCBOOK_XSL_FILENAME=docbook-xsl-$DOCBOOK_XSL_VERSION.tar.bz2
export DOCBOOK_DTD_FILENAME=docbook-xml-$DOCBOOK_DTD_VERSION.zip
export GIT_URL=$DATA_DIR/mirror/
