import React, { useEffect } from 'react';
import { View, Text, FlatList, ActivityIndicator } from 'react-native';
import { useDispatch, useSelector } from 'react-redux';
import { fetchOrders } from '../services/orderService';
import { RootState } from '../store';
import OrderList from '../components/orders/OrderList';

const OrdersScreen = ({ navigation }) => {
    const dispatch = useDispatch();
    const orders = useSelector((state: RootState) => state.orders.orders);
    const loading = useSelector((state: RootState) => state.orders.loading);

    useEffect(() => {
        dispatch(fetchOrders());
    }, [dispatch]);

    const renderItem = ({ item }) => (
        <OrderList order={item} onPress={() => navigation.navigate('OrderDetail', { orderId: item.id })} />
    );

    return (
        <View style={{ flex: 1, padding: 16 }}>
            {loading ? (
                <ActivityIndicator size="large" color="#0000ff" />
            ) : (
                <FlatList
                    data={orders}
                    renderItem={renderItem}
                    keyExtractor={(item) => item.id.toString()}
                />
            )}
        </View>
    );
};

export default OrdersScreen;