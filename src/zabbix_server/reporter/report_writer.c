/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
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

#include "common.h"
#include "daemon.h"
#include "zbxself.h"
#include "log.h"
#include "zbxipcservice.h"
#include "zbxserialize.h"
#include "zbxjson.h"
#include "zbxalert.h"
#include "db.h"
#include "report_writer.h"
#include "report_protocol.h"

extern unsigned char	process_type, program_type;
extern int		server_num, process_num;

extern char	*CONFIG_WEBSERVICE_URL;
extern char	*CONFIG_TLS_CA_FILE;
extern char	*CONFIG_TLS_CERT_FILE;
extern char	*CONFIG_TLS_KEY_FILE;


typedef struct
{
	char	*data;
	size_t	alloc;
	size_t	offset;
}
zbx_buffer_t;

static size_t	curl_write_cb(void *ptr, size_t size, size_t nmemb, void *userdata)
{
	size_t		r_size = size * nmemb, buf_alloc;
	zbx_buffer_t	*buf = (zbx_buffer_t *)userdata;

	buf_alloc = (0 == buf->alloc ? r_size : buf->alloc);
	while (buf_alloc - buf->offset < r_size)
		buf_alloc *= 2;

	if (buf_alloc != buf->alloc)
	{
		buf->data = zbx_realloc(buf->data, buf_alloc);
		buf->alloc = buf_alloc;
	}

	memcpy(buf->data + buf->offset, (const char *)ptr, r_size);
	buf->offset += r_size;

	return r_size;
}

/******************************************************************************
 *                                                                            *
 * Function: rw_get_report                                                    *
 *                                                                            *
 * Purpose: get report from web service                                       *
 *                                                                            *
 * Parameters: url         - [IN] the report url                              *
 *             cookie      - [IN] the authentication cookie                   *
 *             width       - [IN] the report width                            *
 *             height      - [IN] the report height                           *
 *             report      - [OUT] the downloaded report                      *
 *             report_size - [OUT] the report size                            *
 *                                                                            *
 * Return value: SUCCEED - the report was donwloaded successfully             *
 *               FAIL    - otherwise                                          *
 *                                                                            *
 ******************************************************************************/
static int	rw_get_report(const char *url, const char *cookie, const char *width, const char *height, char **report,
		size_t *report_size, char **error)
{
#if !defined(HAVE_LIBCURL)
	ZBX_UNUSED(url);
	ZBX_UNUSED(cookie);
	ZBX_UNUSED(width);
	ZBX_UNUSED(height);
	ZBX_UNUSED(report);
	ZBX_UNUSED(report_size);

	*error = zbx_strdup(NULL, "application compiled without cURL library");
	return FAIL;

#else
	struct zbx_json		j;
	char			*cookie_value;
	int			ret = FAIL;
	long			httpret;
	zbx_buffer_t		response = {NULL, 0, 0};
	CURL			*curl = NULL;
	CURLcode		err;
	CURLoption		opt;
	struct curl_slist	*headers = NULL;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() url:%s width:%s height:%s", __func__, url, ZBX_NULL2EMPTY_STR(width),
			ZBX_NULL2EMPTY_STR(height));

	cookie_value = zbx_dsprintf(NULL, "zbx_session=%s", cookie);

	zbx_json_init(&j, 1024);
	zbx_json_addstring(&j, ZBX_PROTO_TAG_URL, url, ZBX_JSON_TYPE_STRING);

	zbx_json_addobject(&j, ZBX_PROTO_TAG_HTTP_HEADERS);
	zbx_json_addstring(&j, "Cookie", cookie_value, ZBX_JSON_TYPE_STRING);
	zbx_json_close(&j);

	zbx_json_addobject(&j, ZBX_PROTO_TAG_PARAMETERS);
	zbx_json_addstring(&j, "width", width, ZBX_JSON_TYPE_STRING);
	zbx_json_addstring(&j, "height", height, ZBX_JSON_TYPE_STRING);
	zbx_json_close(&j);

	if (NULL == (curl = curl_easy_init()))
	{
		*error = zbx_strdup(NULL, "cannot initialize cURL library");
		goto out;
	}

	headers = curl_slist_append(headers, "Content-Type:application/json");

	if (CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_COOKIEFILE, "")) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_FOLLOWLOCATION, 1L)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_WRITEFUNCTION, curl_write_cb)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_WRITEDATA, &response)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_TIMEOUT, 60)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_POST, 1L)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_URL, CONFIG_WEBSERVICE_URL)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_HTTPHEADER, headers)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_POSTFIELDS, j.buffer)))
	{
		*error = zbx_dsprintf(*error, "cannot set cURL option %d: %s.", (int)opt, curl_easy_strerror(err));
		goto out;
	}

	if (NULL != CONFIG_TLS_CA_FILE && '\0' != *CONFIG_TLS_CA_FILE)
	{
		if (CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_CAINFO, CONFIG_TLS_CA_FILE)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_SSLCERT, CONFIG_TLS_CERT_FILE)) ||
			CURLE_OK != (err = curl_easy_setopt(curl, opt = CURLOPT_SSLKEY, CONFIG_TLS_KEY_FILE)))
		{
			*error = zbx_dsprintf(*error, "cannot set cURL option %d: %s.", (int)opt,
					curl_easy_strerror(err));
			goto out;
		}
	}


	if (CURLE_OK != (err = curl_easy_perform(curl)))
	{
		*error = zbx_dsprintf(*error, "could not connect to web service: %s", curl_easy_strerror(err));
		goto out;
	}

	if (CURLE_OK != (err = curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &httpret)))
	{
		*error = zbx_dsprintf(*error, "could not obtain web service response code: %s", curl_easy_strerror(err));
		goto out;
	}

	if (200 != httpret)
	{
		struct zbx_json_parse	jp;

		if (response.offset == response.alloc)
			response.data = zbx_realloc(response.data, response.alloc + 1);

		response.data[response.offset] = '\0';

		if (SUCCEED != zbx_json_open(response.data, &jp))
		{
			*error = response.data;
			response.data = NULL;
		}
		else
		{
			size_t	error_alloc = 0;

			zbx_json_value_by_name_dyn(&jp, ZBX_PROTO_TAG_DETAIL, error, &error_alloc, NULL);
		}

		goto out;
	}

	*report = response.data;
	*report_size = response.offset;
	response.data = NULL;

	ret = SUCCEED;
out:
	zbx_free(response.data);

	curl_slist_free_all(headers);

	if (NULL != curl)
		curl_easy_cleanup(curl);

	zbx_json_clean(&j);
	zbx_free(cookie_value);

	return ret;
#endif
}

/******************************************************************************
 *                                                                            *
 * Function: rw_begin_report                                                  *
 *                                                                            *
 * Purpose: begin report dispatch                                             *
 *                                                                            *
 * Parameters: msg      - [IN] the begin report request message               *
 *             dispatch - [IN] the alerter dispatch                           *
 *             error    - [OUT] the error message                             *
 *                                                                            *
 * Return value: SUCCEED - the report was started successfully                *
 *               FAIL    - otherwise                                          *
 *                                                                            *
 ******************************************************************************/
static int	rw_begin_report(zbx_ipc_message_t *msg, zbx_alerter_dispatch_t *dispatch, char **error)
{
	zbx_vector_ptr_pair_t	params;
	int			i, ret;
	char			*url, *cookie, *subject = "", *width = "1920", *height = "1080", *message = "",
				*report = NULL, *name;
	size_t			report_size = 0;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	zbx_vector_ptr_pair_create(&params);

	report_deserialize_begin_report(msg->data, &name, &url, &cookie, &params);

	for (i = 0; i < params.values_num; i++)
	{
		if (0 == strcmp(params.values[i].first, "subject"))
		{
			subject = (char *)params.values[i].second;
		}
		else if (0 == strcmp(params.values[i].first, "body"))
		{
			message = (char *)params.values[i].second;
		}
		else if (0 == strcmp(params.values[i].first, "width"))
		{
			width = (char *)params.values[i].second;
		}
		else if (0 == strcmp(params.values[i].first, "height"))
		{
			height = (char *)params.values[i].second;
		}
		else
		{
			zabbix_log(LOG_LEVEL_WARNING, "unsupported paramter: %s=%s", (char *)params.values[i].first,
					(char *)params.values[i].second);
		}
	}

	if (SUCCEED == (ret = rw_get_report(url, cookie, width, height, &report, &report_size, error)))
	{
		ret = zbx_alerter_begin_dispatch(dispatch, subject, message, name, "application/pdf", report,
				report_size, error);
	}

	zbx_free(report);
	zbx_free(name);
	zbx_free(url);
	zbx_free(cookie);

	report_destroy_params(&params);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s report_size:" ZBX_FS_SIZE_T, __func__, zbx_result_string(ret),
			report_size);

	return ret;
}

/******************************************************************************
 *                                                                            *
 * Function: rw_send_report                                                   *
 *                                                                            *
 * Purpose: send report to the recipients using specified media type          *
 *                                                                            *
 * Parameters: msg      - [IN] the send report request message                *
 *             dispatch - [IN] the alerter dispatch                           *
 *             error    - [OUT] the error message                             *
 *                                                                            *
 * Return value: SUCCEED - the report was sent successfully                   *
 *               FAIL    - otherwise                                          *
 *                                                                            *
 ******************************************************************************/
static int	rw_send_report(zbx_ipc_message_t *msg, zbx_alerter_dispatch_t *dispatch, char **error)
{
	int			ret = FAIL;
	zbx_vector_str_t	recipients;
	DB_MEDIATYPE		mt;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	zbx_vector_str_create(&recipients);

	/* The message data is identical (mediatype + recipients) so currently it */
	/* could be forwarded without deserializing/serializing it. Also it could */
	/* have been directly sent from report manager to alert manager, however  */
	/* then 'dispatch' message could be delivered before 'begin' message.     */
	/* While sending through writer does add overhead, it also adds           */
	/* synchronization. And the overhead is only at writer's side.            */
	report_deserialize_send_report(msg->data, &mt, &recipients);
	ret = zbx_alerter_send_dispatch(dispatch, &mt, &recipients, error);

	zbx_db_mediatype_clean(&mt);
	zbx_vector_str_clear_ext(&recipients, zbx_str_free);
	zbx_vector_str_destroy(&recipients);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s", __func__, zbx_result_string(ret));

	return ret;
}

/******************************************************************************
 *                                                                            *
 * Function: rw_end_report                                                    *
 *                                                                            *
 * Purpose: finish report dispatch                                            *
 *                                                                            *
 * Parameters: dispatch - [IN] the alerter dispatch                           *
 *             error    - [OUT] the error message                             *
 *                                                                            *
 * Return value: SUCCEED - the report was finished successfully               *
 *               FAIL    - otherwise                                          *
 *                                                                            *
 ******************************************************************************/
static int	rw_end_report(zbx_alerter_dispatch_t *dispatch, char **error)
{
	int	ret, sent_num;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	ret = zbx_alerter_end_dispatch(dispatch, &sent_num, error);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s sent:%d/%d", __func__, zbx_result_string(ret), sent_num,
			dispatch->total_num);

	return ret;
}

/******************************************************************************
 *                                                                            *
 * Function: rw_send_result                                                   *
 *                                                                            *
 * Purpose: send report result back to manager                                *
 *                                                                            *
 * Parameters: socket - [IN] the report manager IPC socket                    *
 *             status - [IN] the report status                                *
 *             error  - [IN] the error message                                *
 *                                                                            *
 ******************************************************************************/
static void	rw_send_result(zbx_ipc_socket_t *socket, int status, char *error)
{
	unsigned char	*data;
	zbx_uint32_t	size;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() status:%d error:%s", __func__, status, ZBX_NULL2EMPTY_STR(error));

	size = report_serialize_response(&data, status, error);
	zbx_ipc_socket_write(socket, ZBX_IPC_REPORTER_RESULT, data, size);
	zbx_free(data);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}

/******************************************************************************
 *                                                                            *
 * Function: report_writer_thread                                             *
 *                                                                            *
 ******************************************************************************/
ZBX_THREAD_ENTRY(report_writer_thread, args)
{
#define	ZBX_STAT_INTERVAL	5	/* if a process is busy and does not sleep then update status not faster than */
					/* once in STAT_INTERVAL seconds */

	pid_t			ppid;
	char			*error = NULL;
	zbx_ipc_socket_t	socket;
	zbx_ipc_message_t	message;
	zbx_alerter_dispatch_t	dispatch = {0};
	int			report_status = FAIL, started_num = 0, sent_num = 0, finished_num = 0;
	double			time_now, time_stat, time_idle = 0, time_wake;

	process_type = ((zbx_thread_args_t *)args)->process_type;
	server_num = ((zbx_thread_args_t *)args)->server_num;
	process_num = ((zbx_thread_args_t *)args)->process_num;

	zbx_setproctitle("%s #%d starting", get_process_type_string(process_type), process_num);

	zbx_ipc_message_init(&message);

	if (FAIL == zbx_ipc_socket_open(&socket, ZBX_IPC_SERVICE_REPORTER, SEC_PER_MIN, &error))
	{
		zabbix_log(LOG_LEVEL_CRIT, "cannot connect to reporting service: %s", error);
		zbx_free(error);
		exit(EXIT_FAILURE);
	}

	ppid = getppid();
	zbx_ipc_socket_write(&socket, ZBX_IPC_REPORTER_REGISTER, (unsigned char *)&ppid, sizeof(ppid));

	zabbix_log(LOG_LEVEL_INFORMATION, "%s #%d started [%s #%d]", get_program_type_string(program_type),
			server_num, get_process_type_string(process_type), process_num);

	update_selfmon_counter(ZBX_PROCESS_STATE_BUSY);

	zbx_setproctitle("%s #%d started", get_process_type_string(process_type), process_num);

	time_stat = zbx_time();

	while (ZBX_IS_RUNNING())
	{
		time_now = zbx_time();

		if (ZBX_STAT_INTERVAL < time_now - time_stat)
		{
			zbx_setproctitle("%s #%d [reports started %d, sent %d, finished %d, idle " ZBX_FS_DBL
					" sec during " ZBX_FS_DBL " sec]", get_process_type_string(process_type),
					process_num, started_num, sent_num, finished_num, time_idle,
					time_now - time_stat);

			time_stat = time_now;
			time_idle = 0;
			started_num = 0;
			sent_num = 0;
			finished_num = 0;
		}

		update_selfmon_counter(ZBX_PROCESS_STATE_IDLE);

		if (SUCCEED != zbx_ipc_socket_read(&socket, &message))
		{
			zabbix_log(LOG_LEVEL_CRIT, "cannot read reporter service request");
			exit(EXIT_FAILURE);
		}

		update_selfmon_counter(ZBX_PROCESS_STATE_BUSY);

		time_wake = zbx_time();
		zbx_update_env(time_wake);
		time_idle += time_wake = time_now;

		switch (message.code)
		{
			case ZBX_IPC_REPORTER_BEGIN_REPORT:
				if (SUCCEED != (report_status = rw_begin_report(&message, &dispatch, &error)))
					zabbix_log(LOG_LEVEL_DEBUG, "failed to begin report dispatch: %s", error);
				else
					started_num++;
				break;
			case ZBX_IPC_REPORTER_SEND_REPORT:
				if (SUCCEED == report_status)
				{
					if (SUCCEED != (report_status = rw_send_report(&message, &dispatch, &error)))
						zabbix_log(LOG_LEVEL_DEBUG, "failed to send report: %s", error);
					else
						sent_num++;
				}
				break;
			case ZBX_IPC_REPORTER_END_REPORT:
				if (SUCCEED == report_status)
				{
					if (SUCCEED != (report_status = rw_end_report(&dispatch, &error)))
						zabbix_log(LOG_LEVEL_DEBUG, "failed to end report dispatch: %s", error);
					else
						finished_num++;
				}

				rw_send_result(&socket, report_status, error);
				zbx_free(error);
				break;
		}

		zbx_ipc_message_clean(&message);
	}

	zbx_setproctitle("%s #%d [terminated]", get_process_type_string(process_type), process_num);

	while (1)
		zbx_sleep(SEC_PER_MIN);
}
