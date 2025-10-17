import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import orderService from '../services/orderService';

export const fetchOrders = createAsyncThunk('orders/fetchOrders', async () => {
    const response = await orderService.getOrders();
    return response.data;
});

export const createOrder = createAsyncThunk('orders/createOrder', async (orderData) => {
    const response = await orderService.createOrder(orderData);
    return response.data;
});

const ordersSlice = createSlice({
    name: 'orders',
    initialState: {
        orders: [],
        status: 'idle',
        error: null,
    },
    reducers: {
        clearOrders: (state) => {
            state.orders = [];
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchOrders.pending, (state) => {
                state.status = 'loading';
            })
            .addCase(fetchOrders.fulfilled, (state, action) => {
                state.status = 'succeeded';
                state.orders = action.payload;
            })
            .addCase(fetchOrders.rejected, (state, action) => {
                state.status = 'failed';
                state.error = action.error.message;
            })
            .addCase(createOrder.fulfilled, (state, action) => {
                state.orders.push(action.payload);
            });
    },
});

export const { clearOrders } = ordersSlice.actions;

export default ordersSlice.reducer;