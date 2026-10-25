// Mock of core/ajax: tests set global.mockCall to a jest.fn returning a promise.
export default {
    call: (requests) => requests.map((r) => global.mockCall(r)),
};
