jQuery(document).ready(function () {

    if (jQuery('#grades-management-membership').length > 0) {
        jQuery('#grades-management-membership').remove()
    }

    jQuery('#save_all').click(function (e) {
        e.preventDefault();
        jQuery('.save_grade').each(function () {
            let userid = jQuery(this).data('userid');
            saveGrade(userid);
        })
    });

    jQuery('.save_grade').click(function (e) {
        e.preventDefault();
        let userid = jQuery(this).data('userid');
        saveGrade(userid);
    });

    function saveGrade(userid) {
        let grade = parseInt(jQuery('#grade_' + userid).val(), 10);
        if (!isNaN(grade)) {
            let comment = jQuery('#comment_' + userid).val();
            console.log("save grade for ", userid, grade);

            jQuery('#wordpress-lti-saving_grade_' + userid).show();
            jQuery('#container_grade_' + userid).hide();
            jQuery('#wordpress-lti-saved_ok_grade_' + userid).hide();
            let data = {
                'action': 'save_grade_lti',
                'userid': userid,
                'grade': grade,
                'comment': comment
            };

            jQuery.post(ajaxurl, data).done(function (response) {
                var json = JSON.parse(response);
                if (!json.result) {
                    alert(json.error);
                } else {
                    jQuery('#wordpress-lti-saved_ok_grade_' + userid).show();
                }
            })
                .fail(function (xhr, status, error) {
                    // error handling
                    alert(gradesManagementJS.error);
                }).always(function () {
                jQuery('#container_grade_' + userid).show();
                jQuery('#wordpress-lti-saving_grade_' + userid).hide();
            });
        }

    }
});