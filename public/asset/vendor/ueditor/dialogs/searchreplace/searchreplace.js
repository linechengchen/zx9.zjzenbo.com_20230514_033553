function clickHandler(e,t,a){for(var r=0,c=e.length;r<c;r++)e[r].className="";a.className="focus";for(var n=a.getAttribute("tabSrc"),s=0,i=t.length;s<i;s++){var o=t[s],l=o.getAttribute("id");o.style.zIndex=l!=n?1:200}}function switchTab(e){for(var e=$G(e).children,t=e[0].children,a=e[1].children,r=0,c=t.length;r<c;r++){var n=t[r];"focus"===n.className&&clickHandler(t,a,n),n.onclick=function(){clickHandler(t,a,this)}}}function getMatchCase(e){return!!$G(e).checked}editor.firstForSR=0,editor.currentRangeForSR=null,$G("searchtab").onmousedown=function(){$G("search-msg").innerHTML="",$G("replace-msg").innerHTML=""},$G("nextFindBtn").onclick=function(e,t,a){var r=$G("findtxt").value;if(!r)return!1;r={searchStr:r,dir:1,casesensitive:getMatchCase("matchCase")},frCommond(r)||(r=editor.selection.getRange().createBookmark(),$G("search-msg").innerHTML=lang.getEnd,editor.selection.getRange().moveToBookmark(r).select())},$G("nextReplaceBtn").onclick=function(e,t,a){var r=$G("findtxt1").value;if(!r)return!1;r={searchStr:r,dir:1,casesensitive:getMatchCase("matchCase1")},frCommond(r)},$G("preFindBtn").onclick=function(e,t,a){var r=$G("findtxt").value;if(!r)return!1;r={searchStr:r,dir:-1,casesensitive:getMatchCase("matchCase")},frCommond(r)||($G("search-msg").innerHTML=lang.getStart)},$G("preReplaceBtn").onclick=function(e,t,a){var r=$G("findtxt1").value;if(!r)return!1;r={searchStr:r,dir:-1,casesensitive:getMatchCase("matchCase1")},frCommond(r)},$G("repalceBtn").onclick=function(){editor.trigger("clearLastSearchResult");var e=$G("findtxt1").value.replace(/^\s|\s$/g,""),t=$G("replacetxt").value.replace(/^\s|\s$/g,"");return!!e&&(!(e==t||!getMatchCase("matchCase1")&&e.toLowerCase()==t.toLowerCase())&&(t={searchStr:e,dir:1,casesensitive:getMatchCase("matchCase1"),replaceStr:t},void frCommond(t)))},$G("repalceAllBtn").onclick=function(){var e=$G("findtxt1").value.replace(/^\s|\s$/g,""),t=$G("replacetxt").value.replace(/^\s|\s$/g,"");if(!e)return!1;if(e==t||!getMatchCase("matchCase1")&&e.toLowerCase()==t.toLowerCase())return!1;t={searchStr:e,casesensitive:getMatchCase("matchCase1"),replaceStr:t,all:!0},t=frCommond(t);t&&($G("replace-msg").innerHTML=lang.countMsg.replace("{#count}",t))};var frCommond=function(e){return editor.execCommand("searchreplace",e)};switchTab("searchtab"),dialog.onclose=function(){editor.trigger("clearLastSearchResult")};