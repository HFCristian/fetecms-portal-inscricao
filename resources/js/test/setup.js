import '@testing-library/jest-dom';

// jsdom não implementa scrollIntoView; componentes que rolam para o fim (ex.: chat)
// chamariam uma função inexistente nos testes. Stub no-op global.
if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = () => {};
}
