/*
** Zabbix
** Copyright (C) 2001-2022 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

#include "test_get_value_ssh.h"

#include "zbxsysinfo.h"
#include "../../../src/zabbix_server/poller/checks_ssh.h"

#if defined(HAVE_SSH)
#include "../../../src/zabbix_server/poller/ssh_run.c"
#endif

#if defined(HAVE_SSH2)
#include "../../../src/zabbix_server/poller/ssh2_run.c"
#endif

int	__wrap_ssh_run(DC_ITEM *item, AGENT_RESULT *result, const char *encoding, const char *options);

#if defined(HAVE_SSH2) || defined(HAVE_SSH)
int	zbx_get_value_ssh_test_run(DC_ITEM *item, char **error)
{
	AGENT_RESULT	result;
	int		ret;

	zbx_init_agent_result(&result);
	ret = get_value_ssh(item, &result);

	if (NULL != result.msg && '\0' != *(result.msg))
	{
		*error = zbx_malloc(NULL, sizeof(char) * strlen(result.msg));
		zbx_strlcpy(*error, result.msg, strlen(result.msg) * sizeof(char));
	}

	zbx_free_agent_result(&result);

	return ret;
}
#endif /*POLLER_GET_VALUE_SSH_TEST_H*/

int	__wrap_ssh_run(DC_ITEM *item, AGENT_RESULT *result, const char *encoding, const char *options)
{
	int		ret = NOTSUPPORTED;
	char		*err_msg = NULL;
#ifdef HAVE_SSH
	ssh_session	session;
#endif
#ifdef HAVE_SSH2
	LIBSSH2_SESSION	*session;
#endif

	ZBX_UNUSED(item);
	ZBX_UNUSED(result);
	ZBX_UNUSED(encoding);

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

#ifdef HAVE_SSH
	/* initializes an SSH session object */
	if (NULL == (session = ssh_new()))
	{
		SET_MSG_RESULT(result, zbx_strdup(NULL, "Cannot initialize SSH session"));
		zabbix_log(LOG_LEVEL_DEBUG, "Cannot initialize SSH session");

		goto ret;
	}
#endif
#ifdef HAVE_SSH2
	/* initializes an SSH session object */
	if (NULL == (session = libssh2_session_init()))
	{
		SET_MSG_RESULT(result, zbx_strdup(NULL, "Cannot initialize SSH session"));
		goto ret;
	}
#endif

#if defined(HAVE_SSH) || defined(HAVE_SSH2)
	if (0 != ssh_parse_options(session, options, &err_msg))
	{
		SET_MSG_RESULT(result, err_msg);
		goto session_free;
	}
	else
	{
		ret = SYSINFO_RET_OK;
	}
#else
	ZBX_UNUSED(options);

	ret = SYSINFO_RET_OK;
#endif

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s", __func__, zbx_result_string(ret));

session_free:
#ifdef HAVE_SSH
	ssh_free(session);
#endif
#ifdef HAVE_SSH2
	libssh2_session_free(session);
#endif
ret:
	return ret;
}
