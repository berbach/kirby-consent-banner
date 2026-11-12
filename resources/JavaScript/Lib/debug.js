let Debug = {};

Debug.output = window.DEVMODE ?? true;

Debug.setDevMode = function (mode){
    this.output = mode;
}
Debug.getArguments = function (args) {
    let arr = [];
    for (let i = 0; i < args.length; i++) {
        arr[i] = args[i];
    }
    return arr;
};

Debug.info = function () {
    this.write("info", this.getArguments(arguments));
};

Debug.log = function () {
    this.write("log", this.getArguments(arguments));
};

Debug.warn = function () {
    this.write("warn", this.getArguments(arguments));
};

Debug.error = function () {
    this.write("error", this.getArguments(arguments));
};

Debug.debug = function () {
    this.write("debug", this.getArguments(arguments));
};

Debug.write = function (level, args) {
    if (this.output && typeof(console) === "object")
        if (typeof InstallTrigger !== 'undefined')
            console[level].apply(this, args);
        else if ( Object.prototype.toString.call(window.HTMLElement).indexOf('Constructor') > 0 )
            window.console.log(args[0]);
        else
            window.console[level](args);
};

export default Debug;
