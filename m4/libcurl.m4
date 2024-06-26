# LIBCURL_CHECK_CONFIG ([DEFAULT-ACTION], [MINIMUM-VERSION],
#                       [ACTION-IF-YES], [ACTION-IF-NO])
# ----------------------------------------------------------
#      David Shaw <dshaw@jabberwocky.com>   May-09-2006
#
# Checks for libcurl.  DEFAULT-ACTION is the string yes or no to
# specify whether to default to --with-libcurl or --without-libcurl.
# If not supplied, DEFAULT-ACTION is yes.  MINIMUM-VERSION is the
# minimum version of libcurl to accept.  Pass the version as a regular
# version number like 7.10.1. If not supplied, any version is
# accepted.  ACTION-IF-YES is a list of shell commands to run if
# libcurl was successfully found and passed the various tests.
# ACTION-IF-NO is a list of shell commands that are run otherwise.
# Note that using --without-libcurl does run ACTION-IF-NO.
#
# This macro #defines HAVE_LIBCURL if a working libcurl setup is
# found, and sets @LIBCURL_LIBS@ and @LIBCURL_CFLAGS@ to the necessary
# values.  Other useful defines are LIBCURL_FEATURE_xxx where xxx are
# the various features supported by libcurl, and LIBCURL_PROTOCOL_yyy
# where yyy are the various protocols supported by libcurl.  Both xxx
# and yyy are capitalized.  See the list of AH_TEMPLATEs at the top of
# the macro for the complete list of possible defines.  Shell
# variables $libcurl_feature_xxx and $libcurl_protocol_yyy are also
# defined to 'yes' for those features and protocols that were found.
# Note that xxx and yyy keep the same capitalization as in the
# curl-config list (e.g. it's "HTTP" and not "http").
#
# Users may override the detected values by doing something like:
# LIBCURL_LIBS="-lcurl" LIBCURL_CFLAGS="-I/usr/myinclude" ./configure
#
# For the sake of sanity, this macro assumes that any libcurl that is
# found is after version 7.7.2, the first version that included the
# curl-config script.  Note that it is very important for people
# packaging binary versions of libcurl to include this script!
# Without curl-config, we can only guess what protocols are available,
# or use curl_version_info to figure it out at runtime.

AC_DEFUN([LIBCURL_CHECK_CONFIG],
[
  _libcurl_config="no"

  AC_ARG_WITH(libcurl,
     [
If you want to use cURL library:
AS_HELP_STRING([--with-libcurl@<:@=DIR@:>@],[use cURL package @<:@default=no@:>@, optionally specify path to curl-config])],
        [
        if test "x$withval" = "xno"; then
            want_curl="no"
        elif test "x$withval" = "xyes"; then
            want_curl="yes"
        else
            want_curl="yes"
            _libcurl_config=$withval
        fi
        ],
        [want_curl=ifelse([$1],,[no],[$1])])

	if test "x$want_curl" != "xno"; then

		AC_PROG_AWK

		_libcurl_version_parse="eval $AWK '{split(\$NF,A,\".\"); X=256*256*A[[1]]+256*A[[2]]+A[[3]]; print X;}'"

		_libcurl_try_link=no

		AC_PATH_PROG([_libcurl_config], [curl-config], [])

		if test -x "$_libcurl_config"; then
			AC_CACHE_CHECK([for the version of libcurl],
				[libcurl_cv_lib_curl_version],
				[libcurl_cv_lib_curl_version=`$_libcurl_config --version | $AWK '{print $[]2}'`]
			)

			_libcurl_version=`echo $libcurl_cv_lib_curl_version | $_libcurl_version_parse`
			_libcurl_wanted=`echo ifelse([$2],,[0],[$2]) | $_libcurl_version_parse`

			if test $_libcurl_wanted -gt 0; then
				AC_CACHE_CHECK([for libcurl >= version $2],
					[libcurl_cv_lib_version_ok],[
						if test $_libcurl_version -ge $_libcurl_wanted; then
							libcurl_cv_lib_version_ok=yes
							else
							libcurl_cv_lib_version_ok=no
						fi
					]
				)
			fi

			if test $_libcurl_wanted -eq 0 || test "x$libcurl_cv_lib_version_ok" = "xyes"; then
				if test "x$LIBCURL_CFLAGS" = "x"; then
					LIBCURL_CFLAGS=`$_libcurl_config --cflags`
				fi

				if test "x$LIBCURL_LIBS" = "x"; then
					_curl_dir_lib=`$_libcurl_config --prefix`
					_curl_dir_lib="$_curl_dir_lib/lib"
					_full_libcurl_libs=`$_libcurl_config --libs`
					for i in $_full_libcurl_libs; do
						case $i in
							-L*)
								LIBCURL_LDFLAGS="$LIBCURL_LDFLAGS $i"
						;;
							-R*)
								LIBCURL_LDFLAGS="$LIBCURL_LDFLAGS -Wl,$i"
						;;
							-lcurl)
								if test "x$enable_static_libs" = "xyes" -a "x$static_linking_support" = "xno"; then
									i="$_curl_dir_lib/libcurl.a"
								elif test "x$enable_static_libs" = "xyes"; then
									i="${static_linking_support}static $i ${static_linking_support}dynamic"
								fi
								LIBCURL_LIBS="$LIBCURL_LIBS $i"
						;;
							-l*)
								if test "x$enable_static_libs" = "xyes"; then
									_lib_name=`echo "$i" | cut -b3-`
									test -f "$_curl_dir_lib/lib$_lib_name.a" && i="$_curl_dir_lib/lib$_lib_name.a"
								fi
								LIBCURL_LIBS="$LIBCURL_LIBS $i"
						;;
						esac
					done

					_save_curl_cflags="$CFLAGS"
					_save_curl_ldflags="$LDFLAGS"
					_save_curl_libs="$LIBS"
					CFLAGS="$CFLAGS $LIBCURL_CFLAGS"
					LDFLAGS="$LDFLAGS $LIBCURL_LDFLAGS"
					if test "x$enable_static_libs" = "xyes"; then
						test "x$want_openssl" = "xyes" && CFLAGS="$OPENSSL_CFLAGS $CFLAGS"
						test "x$want_openssl" = "xyes" && LDFLAGS="$OPENSSL_LDFLAGS $LDFLAGS"
						test "x$want_ldap" = "xyes" && CFLAGS="$LDAP_CPPFLAGS $CFLAGS"
						test "x$want_ldap" = "xyes" && LDFLAGS="$LDAP_LDFLAGS $LDFLAGS"
					fi

					if test "x$enable_static" = "xyes" -o "x$enable_static_libs" = "xyes"; then
						_full_libcurl_libs=`$_libcurl_config --static-libs`

						if test "x$enable_static_libs" = "xyes" -a -z "$LIBPTHREAD_LIBS"; then
							LIBPTHREAD_CHECK_CONFIG([no])
							if test "x$found_libpthread" != "xyes"; then
								AC_MSG_ERROR([Unable to use libpthread (libpthread check failed)])
							fi
							_full_libcurl_libs="$LIBPTHREAD_LIBS $_full_libcurl_libs"
						fi

						for i in $_full_libcurl_libs; do
							case $i in
								-lcurl)
							;;
								-l*)
									_lib_i=$i
									_lib_name=`echo "$i" | cut -b3-`
									AC_CHECK_LIB($_lib_name , main,[
										if test "x$enable_static_libs" = "xyes"; then
											case $i in
												-lssl|-lcrypto)
													test "x$want_openssl" = "xyes" && i="$OPENSSL_LIBS"
											;;
												-lldap|-lldap_r|-llber)
													test "x$want_ldap" = "xyes" && i="$LDAP_LIBS"
											;;
												-l*)
													test -f "$_curl_dir_lib/lib$_lib_name.a" && i="$_curl_dir_lib/lib$_lib_name.a"
											;;
											esac
										fi
										test -z "${LIBCURL_LIBS##*$_lib_i*}" && LIBCURL_LIBS=`echo "$LIBCURL_LIBS"|sed "s|$_lib_i||g"`
										test -z "${LIBCURL_LIBS##*$i*}" || LIBCURL_LIBS="$LIBCURL_LIBS $i"
									],[
										AC_MSG_ERROR([static library $_lib_name required for linking libcurl not found])
									])
							;;
								-framework|CoreFoundation|Security)
									LIBCURL_LIBS="$LIBCURL_LIBS $i"
							;;
							esac
						done
					fi # "x$enable_static" or "x$enable_static_libs"

					LIBS="$LIBS $LIBCURL_LIBS"

					AC_CHECK_LIB(curl, main, , [AC_MSG_ERROR([libcurl library not found])])

					CFLAGS="$_save_curl_cflags"
					LDFLAGS="$_save_curl_ldflags"
					LIBS="$_save_curl_libs"
					unset _save_curl_cflags
					unset _save_curl_ldflags
					unset _save_curl_libs

					# This is so silly, but Apple actually has a bug in their
					# curl-config script.  Fixed in Tiger, but there are still
					# lots of Panther installs around.
					case "${host}" in
						powerpc-apple-darwin7*)
							LIBCURL_LIBS=`echo $LIBCURL_LIBS | sed -e 's|-arch i386||g'`
						;;
					esac
				fi # "x$LIBCURL_LIBS" = "x"

				_libcurl_try_link=yes
			fi # $_libcurl_wanted -eq 0 || "x$libcurl_cv_lib_version_ok" = "xyes"

			unset _libcurl_wanted
		fi # -x "$_libcurl_config"

		if test "x$_libcurl_try_link" = "xyes"; then
			# we didn't find curl-config, so let's see if the user-supplied
			# link line (or failing that, "-lcurl") is enough.

			LIBCURL_LIBS=${LIBCURL_LIBS-"$_libcurl_libs -lcurl"}

			AC_CACHE_CHECK([whether libcurl is usable],
				[libcurl_cv_lib_curl_usable],[
				_save_curl_libs="${LIBS}"
				_save_curl_ldflags="${LDFLAGS}"
				_save_curl_cflags="${CFLAGS}"
				LIBS="${LIBS} ${LIBCURL_LIBS}"
				LDFLAGS="${LDFLAGS} ${LIBCURL_LDFLAGS}"
				CFLAGS="${CFLAGS} ${LIBCURL_CFLAGS}"
				if test "x$enable_static_libs" = "xyes"; then
					test "x$want_openssl" = "xyes" && CFLAGS=" $OPENSSL_CFLAGS $CFLAGS"
					test "x$want_openssl" = "xyes" && LDFLAGS="$OPENSSL_LDFLAGS $LDFLAGS"
					test "x$want_ldap" = "xyes" && CFLAGS="$LDAP_CPPFLAGS $CFLAGS"
					test "x$want_ldap" = "xyes" && LDFLAGS="$LDAP_LDFLAGS $LDFLAGS"
				fi

				AC_LINK_IFELSE([AC_LANG_PROGRAM([#include <curl/curl.h>
#ifndef NULL
#define NULL (void *)0
#endif],[
/* Try and use a few common options to force a failure if we are
   missing symbols or can't link. */
int x;
curl_easy_setopt(NULL,CURLOPT_URL,NULL);
x=CURL_ERROR_SIZE;
x=CURLOPT_WRITEFUNCTION;
x=CURLOPT_FILE;
x=CURLOPT_ERRORBUFFER;
x=CURLOPT_STDERR;
x=CURLOPT_VERBOSE;
])],libcurl_cv_lib_curl_usable=yes,libcurl_cv_lib_curl_usable=no)

				LIBS="${_save_curl_libs}"
				LDFLAGS="${_save_curl_ldflags}"
				CFLAGS="${_save_curl_cflags}"
				unset _save_curl_libs
				unset _save_curl_ldflags
				unset _save_curl_cflags
			])

			if test "x$libcurl_cv_lib_curl_usable" = "xno"; then
				link_mode="dynamic"
				if test "x$enable_static" = "xyes"; then
					link_mode="static"
				fi
				AC_MSG_ERROR([libcurl is not available for ${link_mode} linking])
			fi

			_save_curl_libs="${LIBS}"
			_save_curl_ldflags="${LDFLAGS}"
			_save_curl_cflags="${CFLAGS}"
			LIBS="${LIBS} ${LIBCURL_LIBS}"
			LDFLAGS="${LDFLAGS} ${LIBCURL_LDFLAGS}"
			CFLAGS="${CFLAGS} ${LIBCURL_CFLAGS}"

			LIBS="${_save_curl_libs}"
			LDFLAGS="${_save_curl_ldflags}"
			CFLAGS="${_save_curl_cflags}"
			unset _save_curl_libs
			unset _save_curl_ldflags
			unset _save_curl_cflags

			AC_DEFINE(HAVE_LIBCURL,1,
				[Define to 1 if you have a functional curl library.])
			AC_SUBST(LIBCURL_CFLAGS)
			AC_SUBST(LIBCURL_LDFLAGS)
			AC_SUBST(LIBCURL_LIBS)
			found_curl="yes"
		else
			unset LIBCURL_LIBS
			unset LIBCURL_CFLAGS
		fi # "x$_libcurl_try_link" = "xyes"

		unset _libcurl_try_link
		unset _libcurl_version_parse
		unset _libcurl_config
		unset _libcurl_version
		unset _libcurl_libs
	fi # "x$want_curl" != "xno"

  if test "x$want_curl" = "xno" || test "x$libcurl_cv_lib_curl_usable" != "xyes"; then
     # This is the IF-NO path
     ifelse([$4],,:,[$4])
  else
     # This is the IF-YES path
     ifelse([$3],,:,[$3])
  fi

])dnl
