import { configureStore } from '@reduxjs/toolkit';
import authReducer from './authSlice';
import ordersReducer from './ordersSlice';

const store = configureStore({
  reducer: {
    auth: authReducer,
    orders: ordersReducer,
  },
});

export default store;