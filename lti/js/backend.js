"use strict";

var wordpress_lti_copy = function (elementId, tooltipId) {
    var copyText = document.getElementById(elementId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);

    var tooltip = document.getElementById(tooltipId);
    tooltip.innerHTML = scriptParams.copied + ": " + copyText.value;
}

var wordpress_lti_copy_outFunc = function (tooltipId) {
    var tooltip = document.getElementById(tooltipId);
    tooltip.innerHTML = scriptParams.copyToClipboard;
}
