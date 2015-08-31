LIBRARIES=__STANDALONE_DOCUMENTATION__
LIBRARY_PATHS=$(addprefix boost/libs/,${LIBRARIES})
LIBRARY_TARGETS=$(addsuffix /build,$(addprefix targets/libraries/,${LIBRARIES}))

all-docs: targets/main-docs targets/asio-docs ${LIBRARY_TARGETS}

targets/libraries/%/build : boost/libs/%/doc doc-build-tools
	find $</html -name "*.html" -exec rm {} \;
	cd $< && b2 -q
	mkdir -p $(@D)
	touch $@

targets/libraries/geometry/build : boost/libs/geometry/doc doc-build-tools bin/doxygen_xml2qbk
	cd $< && python ./make_qbk.py
	rm -r $</generated
	rm -r $</doxy/doxygen_output
	mkdir -p $(@D)
	touch $@

targets/main-docs : boost/doc doc-build-tools targets/clean-main-docs
	cd $< && b2 -q doc
	mkdir -p $(@D)
	touch $@

targets/asio-docs : boost/doc doc-build-tools targets/clean-main-docs
	cd $< && b2 -q asio
	mkdir -p $(@D)
	touch $@

targets/clean-main-docs : boost/doc
	find $</html -name "*.html" -exec rm {} \;
	mkdir -p $(@D)
	touch $@

doc-build-tools: bin/b2 bin/quickbook bin/auto_index

bin/quickbook: bin/b2 targets/headers
	cd boost/tools/quickbook && b2 -q
	cp boost/dist/bin/quickbook bin/quickbook
	echo "using quickbook : $(shell pwd)/bin/quickbook ;" >> user-config.jam

bin/auto_index: bin/b2 targets/headers
	cd boost/tools/auto_index/build && b2 -q
	cp boost/dist/bin/auto_index bin/auto_index
	echo "using auto-index : $(shell pwd)/bin/auto_index ;" >> user-config.jam

bin/doxygen_xml2qbk: bin/b2 targets/headers
	unzip -n -d boost/libs/geometry/doc/src/docutils/tools/doxygen_xml2qbk/contrib ../../tarballs/rapidxml-1.13.zip
	cd boost/libs/geometry/doc/src/docutils/tools/doxygen_xml2qbk && b2 -a -q
	cp boost/dist/bin/doxygen_xml2qbk bin/doxygen_xml2qbk
	rm -r boost/libs/geometry/doc/src/docutils/tools/doxygen_xml2qbk/contrib/rapid*

targets/headers: bin/b2
	cd boost && b2 headers
	mkdir -p targets
	touch targets/headers

bin/b2:
	cd boost/tools/build && ./bootstrap.sh
	cp boost/tools/build/b2 boost/b2
	cp boost/tools/build/b2 bin/b2
