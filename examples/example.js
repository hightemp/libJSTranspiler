var message = 'Hello World';
console.log(message, Math.floor(Math.random() * 100));
console.log(message.charAt(0) + 'ello');
f();
function f() {
  console.log('hi from `f`');
}
function Thing(name) {
  this.name = name;
}
Thing.prototype.sayHello = function() {
  console.log('hi from', this.name);
};
var thing = new Thing('Bob');
thing.sayHello();
