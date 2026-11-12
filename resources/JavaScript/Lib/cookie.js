let _cookie = {};

_cookie.exdays = 30;

_cookie.check = function (cname) {
    let sitename = this.get(cname);

    return sitename !== "";

};

_cookie.set = function (cname, cvalue, exdays) {

    exdays = exdays ? exdays : this.exdays;

    let d = new Date();
    d.setTime(d.getTime() + (exdays*24*60*60*1000));

    let expires = "expires=" + d.toGMTString();
    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
};

_cookie.get = function (cname) {

    let name = cname + "=",
        decodedCookie = decodeURIComponent(document.cookie),
        ca = decodedCookie.split(';');
    for(let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') {
            c = c.substring(1);
        }
        if (c.indexOf(name) === 0) {
            return c.substring(name.length, c.length);
        }
    }
    return "{}";

};

module.exports = _cookie;
