$(function() {

	// Confirm deleting resources
	$("form[data-confirm]").submit(function() {
		if ( ! confirm($(this).attr("data-confirm"))) {
			return false;
		}
	});

});

// Embed latest YouTube video
var channelID = "UCtSUTtkZlALToswWQpWS2kA";
var reqURL = "https://www.youtube.com/feeds/videos.xml?channel_id=";

$.getJSON("https://api.rss2json.com/v1/api.json?rss_url=" + encodeURIComponent(reqURL)+channelID, function(data) {
	 var link = data.items[1].link;
	 var id = link.substr(link.indexOf("=")+1);
	 var link2 = data.items[0].link;
	 var id2 = link2.substr(link2.indexOf("=")+1);
$("#last_week_youtube_video").attr("src","https://youtube.com/embed/"+id + "?controls=0&showinfo=0&rel=0");
$("#latest_youtube_video").attr("src","https://youtube.com/embed/"+id2 + "?controls=0&showinfo=0&rel=0");
});


//
// // Creare's 'Implied Consent' EU Cookie Law Banner v:2.4
// // Conceived by Robert Kent, James Bavington & Tom Foyster
//
// var dropCookie = true;                      // false disables the Cookie, allowing you to style the banner
// var cookieDuration = 14;                    // Number of days before the cookie expires, and the banner reappears
// var cookieName = 'complianceCookie';        // Name of our cookie
// var cookieValue = 'on';                     // Value of cookie
//
// function createDiv(){
//   var bodytag = document.getElementsByTagName('body')[0];
//   var div = document.createElement('div');
//   div.setAttribute('id','cookie-law');
//   div.setAttribute('class', 'panel panel-default')
//   div.innerHTML = '<p id="cookie-message" class="panel-body">Our website uses cookies to improve your experience. <a href="/about-us/privacy-policy/" rel="nofollow" title="Privacy Policy">Find out more</a>. <a class="close-cookie-banner pull-right badge" href="javascript:void(0);" onclick="removeMe();"><span>X</span></a></p>';
//  // Be advised the Close Banner 'X' link requires jQuery
//
//   // bodytag.appendChild(div); // Adds the Cookie Law Banner just before the closing </body> tag
//   // or
//   bodytag.insertBefore(div,bodytag.firstChild); // Adds the Cookie Law Banner just after the opening <body> tag
//
//   document.getElementsByTagName('body')[0].className+=' cookiebanner'; //Adds a class tothe <body> tag when the banner is visible
//
//   createCookie(window.cookieName,window.cookieValue, window.cookieDuration); // Create the cookie
// }
//
//
// function createCookie(name,value,days) {
//   if (days) {
// 	var date = new Date();
// 	date.setTime(date.getTime()+(days*24*60*60*1000));
// 	var expires = "; expires="+date.toGMTString();
//   }
//   else var expires = "";
//   if(window.dropCookie) {
// 	document.cookie = name+"="+value+expires+"; path=/";
//   }
// }
//
// function checkCookie(name) {
//   var nameEQ = name + "=";
//   var ca = document.cookie.split(';');
//   for(var i=0;i < ca.length;i++) {
// 	var c = ca[i];
// 	while (c.charAt(0)==' ') c = c.substring(1,c.length);
// 	if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
//   }
//   return null;
// }
//
// function eraseCookie(name) {
//   createCookie(name,"",-1);
// }
//
// window.onload = function(){
//   if(checkCookie(window.cookieName) != window.cookieValue){
// 	createDiv();
//   }
// }
//
// function removeMe(){
//   var element = document.getElementById('cookie-law');
//   element.parentNode.removeChild(element);
// }
