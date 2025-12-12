define(['jquery',
    'core/ajax',
    'core/notification',
    'core/str',
    'core/url'],
    function ($, Ajax, Notification, Str, Url) {
        'use strict';

        var GenerateReport = function (requestorId, userId, userName, contextId) {
            this.requestorId = parseInt(requestorId);
            this.userId = parseInt(userId);
            this.userName = userName;
            this.start = null;
            this.end = null;
            this.contextId = contextId;
            this.timer = 2500;
            this.file = false;
            this.formdata = {};
            this.polling = null;

            $('#submitdate').click(function (e) {
                e.preventDefault();

                var startDate = document.querySelector('#startInput').valueAsNumber;
                var endDate = document.querySelector('#endInput').valueAsNumber;
                var completion = this.checkCompletion(startDate, endDate);

                var icon = $('<img/>');
                icon.attr('alt', 'loading');
                icon.attr('title', 'loading');
                icon.attr('class', 'local_log_sender_icon loader');
                icon.attr('src', Url.imageUrl('loading', 'local_log_sender'));

                if (!completion) {
                    return Notification.alert(
                        Str.get_string('error'),
                        Str.get_string('error:completiondates', 'local_log_sender')
                    );
                }

                var formdata = {
                    requestorid: this.requestorId,
                    userid: this.userId,
                    username: this.userName,
                    start: startDate,
                    end: endDate,
                    contextid: this.contextId
                };
                this.formdata = formdata;

                Ajax.call([{
                    methodname: 'local_log_sender_generate_time_report',
                    args: { jsonformdata: JSON.stringify(formdata) },
                    done: function () {
                        Str.get_string('client:reportgenerating', 'local_log_sender').done(function (str) {
                            $('#report-area').addClass('alert-warning')
                                .html(str).prepend(icon);
                        });
                        var that = this;
                        (function foo() {
                            that.polling = setInterval(pollFile.bind(that), 7500);
                        })();
                    }.bind(this),
                    fail: Notification.exception
                }]);
            }.bind(this));
        };

        GenerateReport.prototype.checkCompletion = function (startDate, endDate) {
            if (startDate.length == 0 || endDate.length == 0) {
                return false;
            }
            return true;
        };

        var pollFile = function () {
            var dlicon = $('<img/>');
            dlicon.attr('alt', 'download');
            dlicon.attr('title', 'download');
            dlicon.attr('class', 'local_log_sender_icon');
            dlicon.attr('src', Url.imageUrl('download', 'local_log_sender'));

            Ajax.call([{
                methodname: 'local_log_sender_poll_report_file',
                args: { jsonformdata: JSON.stringify(this.formdata) },
                done: function (data) {
                    if (data.status == true) {
                        Str.get_string('client:reportdownload', 'local_log_sender').done(function (str) {
                            var content = $('<a href="' + data.path + '" target="_blank">' + str + '</a>').prepend(dlicon);
                            $('#report-area').removeClass('alert-warning').addClass('alert-success')
                                .html(content);
                        });
                        clearInterval(this.polling);
                        return;
                    }
                }.bind(this),
                fail: Notification.exception
            }]);
        };

        return {
            generateReport: function (requestorId, userId, userName, contextId) {
                return new GenerateReport(requestorId, userId, userName, contextId);
            }
        };
    }
);
